<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DirectorReviewStatus;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\DirectorReview;
use Filament\Actions\Action;
use Filament\Notifications\Notification as Aviso;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a quien envio: el director aprobo o devolvio la pieza.
 */
class DecisionDelDirector extends Notification implements ShouldQueue
{
    use Queueable;

    private string $titulo;

    private string $cuerpo;

    private bool $aprobada;

    private ?string $url;

    public function __construct(DirectorReview $envio)
    {
        $envio->loadMissing(['asset.submission', 'decider']);

        $this->aprobada = $envio->status === DirectorReviewStatus::Approved;
        $this->titulo = ($this->aprobada ? 'Aprobada por el director: ' : 'Devuelta por el director: ')
            .$envio->asset?->original_filename;
        $this->cuerpo = ($envio->decider?->name ?? 'El director')
            .($this->aprobada ? ' aprobo la pieza.' : ' devolvio la pieza.')
            .(filled($envio->decision_comment) ? ' Comentario: '.$envio->decision_comment : '');
        $this->url = $envio->asset?->submission !== null
            ? SubmissionResource::getUrl('edit', ['record' => $envio->asset->submission])
            : null;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $aviso = Aviso::make()
            ->title($this->titulo)
            ->body($this->cuerpo)
            ->icon($this->aprobada ? 'heroicon-o-check-badge' : 'heroicon-o-arrow-uturn-left');

        $this->aprobada ? $aviso->success() : $aviso->danger();

        if ($this->url !== null) {
            $aviso->actions([Action::make('abrir')->label('Ver la carga')->url($this->url)->markAsRead()]);
        }

        return $aviso->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->titulo)
            ->greeting('Hola '.$notifiable->name)
            ->line($this->cuerpo);

        if ($this->url !== null) {
            $mail->action('Ver la carga', $this->url);
        }

        return $this->aprobada ? $mail : $mail->line('Corrige la pieza, subela como nueva version en la misma carga y vuelve a enviarla.');
    }
}
