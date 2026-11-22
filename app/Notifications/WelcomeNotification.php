<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de bienvenida de una cuenta creada por un administrador: lleva el enlace para que la
 * persona defina su propia contraseña (el administrador nunca la conoce). El enlace es el mismo
 * de "restablecer contraseña" y caduca igual (config auth.passwords.users.expire); el parámetro
 * invitation=1 solo hace que la pantalla muestre los textos de bienvenida.
 */
class WelcomeNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'invitation' => 1,
        ]);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $app = config('app.name');

        return (new MailMessage)
            ->subject("Tu cuenta en {$app} está lista: define tu contraseña")
            ->greeting("Hola, {$notifiable->name}")
            ->line("Un administrador creó una cuenta para ti en {$app} con este correo: {$notifiable->getEmailForPasswordReset()}.")
            ->line('Para empezar, elige tu contraseña con el botón de abajo. Es el único paso que falta.')
            ->action('Definir mi contraseña', $url)
            ->line("Por seguridad, el enlace funciona una sola vez y caduca en {$minutes} minutos.")
            ->line('Si caduca, pide a quien te invitó que te envíe uno nuevo o usa «¿Olvidaste tu contraseña?» en la pantalla de inicio de sesión.')
            ->line('Nadie de nuestro equipo conoce tu contraseña ni te la pedirá por correo. Si no esperabas este mensaje, puedes ignorarlo.')
            ->salutation("Saludos, {$app}");
    }
}
