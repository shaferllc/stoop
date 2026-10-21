<?php

namespace App\Livewire\Pages;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\User;
use App\Support\Messaging;
use App\Support\Profanity;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts::stoop')]
class ConversationView extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $conversationId;

    public string $body = '';

    /** @var array<int, mixed> */
    public array $photos = [];

    public ?int $replyTo = null;

    public string $newName = '';

    public string $addUsername = '';

    public ?int $reportingMessage = null;

    public string $reportReason = 'hate';

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ["echo-private:conversation.{$this->conversationId},.message" => '$refresh'];
    }

    public function mount(Conversation $conversation): void
    {
        abort_unless($conversation->participants()->whereKey(Auth::id())->exists(), 404);

        $this->conversationId = $conversation->id;
        $this->newName = (string) $conversation->name;
    }

    protected function conversation(): Conversation
    {
        $conversation = Conversation::with('participants')->findOrFail($this->conversationId);
        abort_unless($conversation->pivotFor(Auth::user()), 404);

        return $conversation;
    }

    public function updatedBody(): void
    {
        Cache::put("typing:{$this->conversationId}:".Auth::id(), true, now()->addSeconds(6));
    }

    public function send(): void
    {
        Messaging::send(Auth::user(), $this->conversation(), $this->body, $this->photos, $this->replyTo);
        Cache::forget("typing:{$this->conversationId}:".Auth::id());
        $this->reset('body', 'photos', 'replyTo');
    }

    public function accept(): void
    {
        DB::table('conversation_participants')->where(['conversation_id' => $this->conversationId, 'user_id' => Auth::id()])
            ->update(['accepted_at' => now()]);
    }

    /**
     * Decline a request. The sender isn't told.
     */
    public function decline(): void
    {
        $this->conversation()->participants()->detach(Auth::id());
        $this->redirectRoute('messages', navigate: true);
    }

    public function toggleArchive(): void
    {
        $pivot = $this->conversation()->pivotFor(Auth::user());
        DB::table('conversation_participants')->where(['conversation_id' => $this->conversationId, 'user_id' => Auth::id()])
            ->update(['archived_at' => $pivot->archived_at ? null : now()]);
        Flux::toast($pivot->archived_at ? 'Moved back to your inbox.' : 'Archived. It comes back if someone writes.');
    }

    /**
     * Toggle an emoji reaction. Up to 12 different reactions per person per message.
     */
    public function react(int $messageId, string $emoji): void
    {
        $user = Auth::user();
        $message = $this->conversation()->messages()->findOrFail($messageId);
        abort_if(mb_strlen($emoji) > 8 || preg_match('/[\p{L}\p{N}\s<>]/u', $emoji), 422);

        $existing = $message->reactions()->where(['user_id' => $user->id, 'emoji' => $emoji]);
        if ($existing->exists()) {
            $existing->delete();
        } elseif ($message->reactions()->where('user_id', $user->id)->count() < 12) {
            $message->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
        }
    }

    public function rename(): void
    {
        $conversation = $this->conversation();
        abort_unless($conversation->is_group && $conversation->isAdmin(Auth::user()) && ! Auth::user()->postingBlock(), 403);
        $this->validate(['newName' => ['nullable', 'string', 'max:60', fn ($a, $v, $fail) => Profanity::contains((string) $v) ? $fail('Pick a name without swearing.') : null]]);
        $conversation->update(['name' => $this->newName ?: null]);
        Flux::toast('Renamed.');
    }

    public function addMember(): void
    {
        $conversation = $this->conversation();
        $user = Auth::user();
        abort_unless($conversation->is_group && $conversation->isAdmin($user) && ! $user->postingBlock(), 403);

        $person = User::where('username', ltrim(trim($this->addUsername), '@'))->first();
        if (! $person) {
            $this->addError('addUsername', 'No one with that username.');

            return;
        }
        if ($conversation->participants->count() >= Conversation::MAX_MEMBERS) {
            $this->addError('addUsername', 'Groups hold up to '.Conversation::MAX_MEMBERS.' people.');

            return;
        }

        Messaging::checkCanAdd($user, $person);
        $conversation->participants()->syncWithoutDetaching([$person->id => ['accepted_at' => now()]]);
        $this->reset('addUsername');
        Flux::toast('Added @'.$person->username.'.');
    }

    public function removeMember(int $userId): void
    {
        $conversation = $this->conversation();
        abort_unless($conversation->is_group && $conversation->isAdmin(Auth::user()) && $userId !== Auth::id(), 403);
        $conversation->participants()->detach($userId);
    }

    public function leave(): void
    {
        $conversation = $this->conversation();
        abort_unless($conversation->is_group, 403);
        $conversation->participants()->detach(Auth::id());

        // Hand the group to the longest-standing member if the last admin leaves.
        if (! $conversation->participants()->wherePivot('role', 'admin')->exists() && $next = $conversation->participants()->orderBy('conversation_participants.created_at')->first()) {
            $conversation->participants()->updateExistingPivot($next->id, ['role' => 'admin']);
        }

        $this->redirectRoute('messages', navigate: true);
    }

    public function block(): void
    {
        $conversation = $this->conversation();
        $other = $conversation->participants->firstWhere('id', '!=', Auth::id());
        abort_if($conversation->is_group || ! $other, 403);

        Auth::user()->silenced()->syncWithoutDetaching([$other->id => ['type' => 'block']]);
        Flux::toast('Blocked @'.$other->username.'.');
    }

    public function reportMessage(): void
    {
        $this->validate(['reportReason' => [Rule::in(array_keys(config('stoop.report_reasons')))]]);
        $message = $this->conversation()->messages()->where('user_id', '!=', Auth::id())->findOrFail($this->reportingMessage);

        MessageReport::firstOrCreate(['message_id' => $message->id, 'user_id' => Auth::id()], ['reason' => $this->reportReason]);
        $this->reportingMessage = null;
        Flux::toast('Reported. A moderator will read this message and the ones around it.');
    }

    public function render(): View
    {
        $user = Auth::user();
        $conversation = $this->conversation();
        $pivot = $conversation->pivotFor($user);

        if ($pivot->accepted_at) {
            DB::table('conversation_participants')->where(['conversation_id' => $conversation->id, 'user_id' => $user->id])->update(['last_read_at' => now()]);
        }

        $messages = $conversation->messages()->with(['user', 'images', 'reactions', 'replyTo.user'])->latest('id')->take(100)->get()->reverse()->values();
        $others = $conversation->participants->where('id', '!=', $user->id);

        // Receipts show only when both people have them on.
        $seenAt = ! $conversation->is_group && $user->read_receipts && ($other = $others->first())?->read_receipts
            ? $other->pivot->last_read_at
            : null;

        $typing = $others->filter(fn ($p) => Cache::has("typing:{$conversation->id}:{$p->id}"))->pluck('name');

        return view('livewire.pages.conversation', compact('conversation', 'pivot', 'messages', 'others', 'seenAt', 'typing'))
            ->title($conversation->titleFor($user));
    }
}
