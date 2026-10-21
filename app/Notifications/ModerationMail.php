<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email copy of a safety-critical notice (a timeout, a ban, an appeal decision), and the
 * daily digest for admins. Sent only to people who keep moderation emails on (the default).
 */
class ModerationMail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(public string $subject, public array $lines, public ?string $url = null, public string $button = 'Open Stoop') {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->moderation_emails && ! $notifiable->is_bot ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting('Hi '.$notifiable->name.',');
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action($this->button, $this->url ?? url('/'))
            ->line('You can turn these emails off in Settings → Safety.');
    }
}
