<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * Atom feeds for feed readers: everything, one line, one stop, or one person.
 */
class FeedController extends Controller
{
    public function latest(): Response
    {
        return $this->feed('Stoop · Latest', route('home', ['tab' => 'latest']), Dispatch::query());
    }

    public function line(string $line): Response
    {
        abort_unless(config("stoop.lines.$line"), 404);

        return $this->feed('Stoop · '.config("stoop.lines.$line.name").' line', route('home', ['line' => $line]), Dispatch::where('line', $line));
    }

    public function stop(string $code): Response
    {
        $code = strtoupper($code);
        abort_unless(config("stoop.stations.$code"), 404);

        return $this->feed('Stoop · '.$code.' '.config("stoop.stations.$code"), route('home', ['stop' => $code]), Dispatch::where('stop', $code));
    }

    public function user(User $user): Response
    {
        return $this->feed('Stoop · @'.$user->username, route('users.show', $user), Dispatch::where('user_id', $user->id));
    }

    /**
     * @param  Builder<Dispatch>  $query
     */
    protected function feed(string $title, string $link, $query): Response
    {
        $dispatches = $query->topLevel()->visibleTo(null)->with('user')->latest()->take(50)->get();

        return response()->view('feeds.atom', compact('title', 'link', 'dispatches'))
            ->header('Content-Type', 'application/atom+xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
