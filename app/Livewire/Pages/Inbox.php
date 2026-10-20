<?php

namespace App\Livewire\Pages;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Messaging;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Messages')]
class Inbox extends Component
{
    #[Url(except: 'inbox')]
    public string $box = 'inbox';

    /** Usernames to start a conversation with, separated by spaces or commas. */
    #[Url(as: 'to', except: '')]
    public string $to = '';

    public string $groupName = '';

    public function start(): void
    {
        $user = Auth::user();
        $usernames = array_filter(preg_split('/[\s,@]+/', $this->to));
        $people = User::whereIn('username', $usernames)->get();

        if ($people->count() !== count(array_unique(array_map('strtolower', $usernames)))) {
            $this->addError('to', 'We couldn’t find everyone. Check the usernames.');

            return;
        }

        $conversation = Messaging::start($user, $people->all(), $this->groupName ?: null);
        $this->redirectRoute('messages.show', $conversation, navigate: true);
    }

    public function render(): View
    {
        $user = Auth::user();

        $conversations = Conversation::with('participants')
            ->whereHas('participants', fn ($q) => $q->whereKey($user->id)->where(fn ($q) => match ($this->box) {
                'requests' => $q->whereNull('conversation_participants.accepted_at'),
                'archived' => $q->whereNotNull('conversation_participants.accepted_at')->whereNotNull('conversation_participants.archived_at'),
                default => $q->whereNotNull('conversation_participants.accepted_at')->whereNull('conversation_participants.archived_at'),
            }))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->take(100)->get();

        $latest = Message::whereIn('id', Message::selectRaw('max(id)')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id'))
            ->get()->keyBy('conversation_id');

        return view('livewire.pages.inbox', [
            'conversations' => $conversations,
            'latest' => $latest,
            'requestCount' => Conversation::whereHas('participants', fn ($q) => $q->whereKey($user->id)->whereNull('conversation_participants.accepted_at'))->count(),
        ]);
    }
}
