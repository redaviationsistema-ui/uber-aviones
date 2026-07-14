<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage())
            ->subject('Verifica tu correo')
            ->line('Confirma tu correo para activar las acciones protegidas de tu cuenta.')
            ->action('Verificar correo', $verificationUrl)
            ->line('Si no creaste esta cuenta, puedes ignorar este mensaje.');
    }
}
