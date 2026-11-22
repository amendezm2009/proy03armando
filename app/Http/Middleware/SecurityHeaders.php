<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad HTTP en todas las respuestas (web, API y archivos públicos). Una cabecera que la
 * respuesta ya trae (por ejemplo la política de contenido propia de los SVG y los archivos de productos) no se
 * sustituye.
 *
 * - X-Frame-Options y frame-ancestors: la página no se puede incrustar en otra (clickjacking).
 * - X-Content-Type-Options: el navegador no adivina el tipo de un archivo.
 * - Referrer-Policy: al salir a otro sitio solo se envía el dominio, no la dirección completa.
 * - Permissions-Policy: solo la propia página puede usar la cámara (editor de la foto de perfil).
 * - Strict-Transport-Security: solo en HTTPS y si config('security.hsts') está activo.
 * - Content-Security-Policy: solo en páginas HTML. Los scripts en línea necesitan el nonce de Vite::cspNonce().
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $mode = config('security.csp');
        $isHtml = str_contains((string) $response->headers->get('Content-Type'), 'text/html');

        if ($isHtml && in_array($mode, ['enforce', 'report-only'], true)) {
            $name = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

            if (! $response->headers->has($name)) {
                $response->headers->set($name, $this->policy($request, $nonce));
            }
        }

        // PHP añade X-Powered-By por su cuenta (no pasa por la respuesta de Laravel): se quita también ahí.
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure() && config('security.hsts')) {
            $headers['Strict-Transport-Security'] = 'max-age='.config('security.hsts_max_age').'; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Solo se permite lo que la aplicación usa: sus propios archivos, las fuentes de Google Fonts (el layout las
     * carga) e imágenes o videos locales (data: y blob: los usa el editor de fotos). En desarrollo, con el
     * servidor de Vite en marcha (public/hot), también se permite su dirección.
     */
    private function policy(Request $request, string $nonce): string
    {
        $dev = $this->viteDevServer();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', ...$dev],
            'font-src' => ["'self'", 'https://fonts.gstatic.com', 'data:', ...$dev],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'media-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", ...$dev, ...array_map(fn (string $url): string => preg_replace('~^http~', 'ws', $url), $dev)],
            'worker-src' => ["'self'", 'blob:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        $policy = collect($directives)->map(fn (array $sources, string $name): string => $name.' '.implode(' ', $sources))->implode('; ');

        return $request->isSecure() ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    /**
     * Orígenes del servidor de Vite en desarrollo (solo si está en marcha y nunca en producción). La dirección de
     * public/hot puede ser [::1], pero Vite también conecta por "localhost" y 127.0.0.1 (su WebSocket de recarga
     * automática), así que se permiten las tres con el mismo puerto. Los navegadores NO aceptan una dirección IPv6
     * como origen en una CSP (la descartan en silencio): si Vite está en [::1] se permiten los esquemas "http:" y
     * "ws:" (que la política convierte para connect-src), solo en desarrollo.
     *
     * @return list<string>
     */
    private function viteDevServer(): array
    {
        if (app()->isProduction() || ! Vite::isRunningHot()) {
            return [];
        }

        $url = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
        $port = parse_url($url, PHP_URL_PORT) ?: 5173;

        $origins = [$url, "http://localhost:{$port}", "http://127.0.0.1:{$port}", "http://[::1]:{$port}"];

        if (str_contains($url, '[')) {
            $origins[] = 'http:';
        }

        return array_values(array_unique($origins));
    }
}
