<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class WeeklyDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $digest
     */
    public function __construct(public array $digest, public ?User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'This week on Stoop: '.($this->digest['talk']?->body ? str($this->digest['talk']->body)->limit(50) : 'the week in DC'));
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->recipient ? ['List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'] : []);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-digest', with: ['unsubscribe' => $this->recipient ? $this->unsubscribeUrl() : null]);
    }

    protected function unsubscribeUrl(): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['user' => $this->recipient->id]);
    }
}
