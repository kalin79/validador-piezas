<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\BandejaDirector;
use App\Models\DirectorReview;
use Filament\Actions\Action;
use Filament\Notifications\Notification as Aviso;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al director: hay una pieza esperando su decision.
 *
 * Guarda texto plano y no el modelo: se envia en cola, y al reconstruirlo ahi
 * no hay usuario autenticado ni panel activo.
 */
class PiezaEnviadaAlDirector extends Notification implements ShouldQueue
{
    use Queueable;

    private string $titulo;

    private string $cuerpo;

    private string $url;

    public function __construct(DirectorReview $envio)
    {
        $envio->loadMissing(['asset', 'sender', 'brand.client']);

        $this->titulo = 'Pieza para aprobar: '.$envio->asset?->original_filename;
        $this->cuerpo = sprintf(
            '%s · %s. Enviada por %s. Veredicto del validador: %s%s.%s',
            $envio->brand?->client?->name ?? '—',
            $envio->brand?->name ?? '—',
            $envio->sender?->name ?? '—',
            $envio->verdict_at_send->label(),
            $envio->score_at_send !== null ? ' ('.rtrim(rtrim(number_format($envio->score_at_send, 1, '.', ''), '0'), '.').')' : '',
            filled($envio->sender_note) ? ' Nota: '.$envio->sender_note : '',
        );
        $this->url = BandejaDirector::getUrl();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return Aviso::make()
            ->title($this->titulo)
            ->body($this->cuerpo)
            ->icon('heroicon-o-paper-airplane')
            ->info()
            ->actions([Action::make('abrir')->label('Abrir bandeja')->url($this->url)->markAsRead()])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->titulo)
            ->greeting('Hola '.$notifiable->name)
            ->line($this->cuerpo)
            ->action('Revisar en el validador', $this->url)
            ->line('La pieza pasa por el validador antes de llegar a ti. La decision final es tuya.');
    }
}
