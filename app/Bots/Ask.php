<?php

namespace App\Bots;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Models\Dispatch;
use App\Support\Mentions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Points unanswered Ask DC questions to similar ones neighbors already answered.
 * It never answers a question itself, and stays out of safety and medical questions.
 */
class Ask extends Bot
{
    // Questions about safety or health go to people (or 911), never to a bot's links.
    public const SENSITIVE = '/\b(911|emergenc\w*|doctor\w*|hospital\w*|clinic\w*|urgent care|symptom\w*|medic\w*|prescri\w*|pharmac\w*|pain|sick|ill|injur\w*|pregnan\w*|overdos\w*|suicid\w*|therap\w*|mental health|rash|fever|covid|vaccin\w*|safe|safety|unsafe|danger\w*|crime\w*|shoot\w*|shot|gun\w*|stab\w*|robb\w*|assault\w*|stalk\w*|police|cops?|abuse\w*|threat\w*)\b/i';

    protected const STOPWORDS = ['about', 'anyone', 'anybody', 'best', 'does', 'from', 'good', 'have', 'here', 'know', 'like', 'looking', 'near', 'need', 'recommend', 'recommendations', 'should', 'some', 'that', 'there', 'thanks', 'their', 'they', 'this', 'what', 'when', 'where', 'which', 'with', 'would', 'your', 'just', 'been', 'into', 'than', 'then', 'them', 'were', 'will', 'want', 'around', 'really', 'place', 'places', 'spot', 'spots'];

    public function username(): string
    {
        return 'ask';
    }

    public function name(): string
    {
        return 'Ask DC';
    }

    public function description(): string
    {
        return 'Links unanswered Ask DC questions to similar ones neighbors already answered.';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => $this->run())->name('bot:ask')->everyThirtyMinutes()->withoutOverlapping();
    }

    /**
     * Reply once to each Ask DC question that's sat 2 to 24 hours without a reply. Returns how many got one.
     */
    public function run(): int
    {
        $bot = $this->account();
        $posted = 0;

        $questions = $this->asks()->whereNull('parent_id')->where('status', 'approved')
            ->whereBetween('created_at', [now()->subDay(), now()->subHours(2)])
            ->whereHas('user', fn ($q) => $q->where('is_bot', false))
            ->whereDoesntHave('replies', fn ($q) => $q->withTrashed()->where(fn ($q) => $q->where('status', 'approved')->orWhere('user_id', $bot->id)))
            ->oldest()->take(5)->get();

        foreach ($questions as $question) {
            if (preg_match(self::SENSITIVE, $question->body) || ! ($similar = $this->similar($question))->count()) {
                continue;
            }
            if (($framing = $this->framing($question->body)) === null) {
                continue;
            }

            // Excerpts lose their mentions and links so quoting them doesn't ping anyone or add a link card.
            $excerpt = fn (Dispatch $d) => Str::limit(trim(preg_replace(['/\s+/', Mentions::URL_PATTERN, Mentions::PATTERN], [' ', '', '$1'], $d->body)), 70);
            $links = $similar->map(fn (Dispatch $d) => '• '.$excerpt($d).' '.route('dispatches.show', $d))->join("\n");
            $body = Str::limit($framing, config('stoop.max_length') - mb_strlen($links) - 2)."\n\n".$links;

            try {
                $this->post(['parentId' => $question->id, 'body' => $body]);
                $posted++;
            } catch (ValidationException) {
                // Replies paused or limited to people the author follows: leave it be.
            }
        }

        return $posted;
    }

    /**
     * Up to 3 answered Ask DC questions sharing at least two key words with this one.
     *
     * @return Collection<int, Dispatch>
     */
    public function similar(Dispatch $question): Collection
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question->body), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => mb_strlen($w) > 3 && ! in_array($w, self::STOPWORDS, true))
            ->unique()->sortByDesc(fn ($w) => mb_strlen($w))->take(6)->values();

        if ($words->count() < 2 || DB::getDriverName() !== 'sqlite') {
            return collect();
        }

        $match = $words->map(fn ($w) => '"'.$w.'"')->join(' OR ');
        $ids = array_column(DB::select('SELECT rowid FROM dispatches_fts WHERE dispatches_fts MATCH ? ORDER BY rank LIMIT 100', [$match]), 'rowid');
        $order = array_flip($ids);

        // ponytail: "shares two words" by 5-letter stems; embeddings would catch rephrased questions
        $shared = fn (Dispatch $d) => $words->filter(fn ($w) => str_contains(mb_strtolower($d->body), mb_substr($w, 0, 5)))->count();

        // Answered: a Best Answer, or an approved reply from a person other than the asker (bots don't count).
        $answered = fn (Dispatch $d) => $d->best_answer_id
            || $d->replies->contains(fn (Dispatch $r) => $r->user_id !== $d->user_id && ! $r->user?->is_bot);

        return $this->asks()->whereNull('parent_id')->where('status', 'approved')->whereKeyNot($question->id)
            ->whereIn('id', $ids)->with(['replies' => fn ($q) => $q->where('status', 'approved'), 'replies.user'])
            ->get()
            ->filter(fn (Dispatch $d) => $answered($d) && $shared($d) >= 2)
            ->sortBy(fn (Dispatch $d) => $order[$d->id])
            ->take(3)->values();
    }

    /**
     * @return Builder<Dispatch>
     */
    protected function asks(): Builder
    {
        return Dispatch::where(fn ($q) => $q->where('type', 'ask')->orWhere('line', 'yellow'));
    }

    /**
     * One short sentence before the links, or null when Claude says the question is sensitive.
     * Claude writes it when a key is set; it never answers.
     */
    protected function framing(string $question): ?string
    {
        $plain = 'No replies yet, but neighbors answered similar questions before:';

        if (! $key = config('services.anthropic.key')) {
            return $plain;
        }

        try {
            $message = (new Client(apiKey: $key))->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: 200,
                outputConfig: ['effort' => 'low'],
                system: 'You are @ask on Stoop, a DC neighborhood network. A neighbor’s question has gone unanswered, and you are about to share links to similar past questions. Write ONE short, friendly sentence (under 140 characters) introducing those links. Do not answer the question, give advice, or mention anyone. If the question is about safety, crime, health or medicine, reply with exactly SKIP.',
                messages: [['role' => 'user', 'content' => $question]],
            );

            $text = $message->stopReason === 'refusal' ? '' : trim((string) collect($message->content)->firstWhere('type', 'text')?->text);
        } catch (APIException $e) {
            Log::warning('Ask bot could not reach Claude', ['error' => $e->getMessage()]);

            return $plain;
        }

        if ($text === 'SKIP') {
            return null;
        }

        // The question is untrusted input: keep any mentions or links Claude echoes out of the reply.
        $text = trim(preg_replace([Mentions::URL_PATTERN, Mentions::PATTERN], '', $text));

        return $text === '' ? $plain : Str::limit($text, 160);
    }
}
