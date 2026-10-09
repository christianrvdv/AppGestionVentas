<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Traduce el bloqueo por login_throttling en una respuesta 429 con un
 * mensaje genérico, sin revelar si el email o el tenant existen.
 *
 * El flujo real: LoginThrottlingListener lanza
 * TooManyLoginAttemptsAuthenticationException durante CheckPassportEvent;
 * AuthenticatorManager la captura como AuthenticationException y la
 * convierte en un fallo de login (redirect a /login por failure_path),
 * que despacha LoginFailureEvent con esa respuesta ya armada. Por eso el
 * hook es LoginFailureEvent (y NO kernel.exception): aquí se puede
 * sobreescribir la respuesta antes de devolverla.
 *
 * Prioridad 10: corre antes que los listeners por defecto (prioridad 0,
 * p. ej. la que consume tokens del limiter) y garantiza el 429.
 */
#[AsEventListener(event: LoginFailureEvent::class, priority: 10)]
final class LoginThrottlingResponseListener
{
    public function __invoke(LoginFailureEvent $event): void
    {
        if (!$event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $event->setResponse(new Response(
            '<!DOCTYPE html>'
            .'<html lang="es"><head><meta charset="UTF-8"><title>Demasiados intentos</title></head>'
            .'<body>'
            .'<h1>Demasiados intentos de inicio de sesión</h1>'
            .'<p>Demasiados intentos fallidos. Probá de nuevo más tarde.</p>'
            .'<p><a href="/login">Volver a iniciar sesión</a></p>'
            .'</body></html>',
            429,
            ['Content-Type' => 'text/html; charset=UTF-8']
        ));
    }
}
