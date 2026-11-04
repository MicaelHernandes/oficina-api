<?php

namespace Domain\Workshop\Application\Mail;

use Domain\Workshop\Domain\Enums\OsStatus;
use Domain\Workshop\Domain\Events\OsStatusUpdatedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class OsStatusUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Status para os quais o cliente precisa decidir sobre o orçamento. */
    private const array DECISION_STATUSES = [OsStatus::PendingApproval, OsStatus::InRenegotiation];

    public function __construct(
        public readonly OsStatusUpdatedEvent $event,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Oficina] Atualização da OS #'.$this->event->orderService->getId()
                .' — '.$this->event->orderService->getStatus()->label(),
        );
    }

    public function content(): Content
    {
        // Com a saga ligada, a decisão do orçamento é do Billing Service, que envia
        // os próprios links assinados; este e-mail só informa o status.
        $requiresDecision = ! config('messaging.saga_enabled')
            && in_array($this->event->orderService->getStatus(), self::DECISION_STATUSES, true);

        return new Content(
            view: 'mail.workshop.os-status-updated',
            with: [
                'approveUrl' => $requiresDecision ? $this->signedUrl('public.os.approve-budget') : null,
                'rejectUrl' => $requiresDecision ? $this->signedUrl('public.os.reject-budget') : null,
            ],
        );
    }

    private function signedUrl(string $routeName): string
    {
        return URL::temporarySignedRoute(
            $routeName,
            now()->addDays(7),
            ['id' => $this->event->orderService->getId()],
        );
    }
}
