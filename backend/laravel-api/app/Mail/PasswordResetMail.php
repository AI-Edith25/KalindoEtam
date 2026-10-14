<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Not ShouldQueue — sent synchronously. This app's queue worker (QUEUE_CONNECTION=database) is a
 * manual per-deploy systemd unit (deploy/README.md), not guaranteed-running infra, and this is a
 * low-volume, user-triggered action — a send failure should surface immediately as a normal API
 * error instead of silently sitting in a queue nobody's processing.
 */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public string $resetUrl,
        public string $userName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset Password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
