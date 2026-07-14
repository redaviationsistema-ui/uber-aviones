<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url('/api/v1/auth/password/reset/'.$this->token.'?email='.urlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage())
            ->subject('Restablece tu contrasena')
            ->line('Recibimos una solicitud para restablecer la contrasena de tu cuenta.')
            ->action('Restablecer contrasena', $url)
            ->line('Este enlace expirara en '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutos.')
            ->line('Si no solicitaste este cambio, puedes ignorar este mensaje.');
    }
}
