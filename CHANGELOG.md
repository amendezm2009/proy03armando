# Registro de cambios

Todos los cambios relevantes de la aplicación. Formato basado en Keep a Changelog; las versiones siguen SemVer (MAYOR.MENOR.PARCHE).

## [1.0.0] - 2026-11-20

Primera versión numerada: reúne las fases 1 a 6 del proyecto.

### Añadido
- Registro, inicio de sesión y cierre de sesión con límite de intentos y contraseñas con Argon2id.
- Recuperación de contraseña, perfil de usuario y foto de perfil con recorte.
- API protegida con Sanctum: tokens con caducidad, límite de peticiones y recursos para clasificaciones, productos, usuarios y perfil.
- Roles y permisos (Spatie) para administrador y cliente.
- Gestión de usuarios con invitaciones, activación y desactivación.
- Catálogo de productos con fotos, videos, códigos de barras y QR, y catálogo público para visitantes.
- Paneles por rol: administrador, cliente y catálogo público.
- Versionado: archivo VERSION, ruta /health, paquetes firmados en partes (php artisan release:package) y script scripts/release.ps1.

### Seguridad
- Cabeceras de seguridad con política de contenido (CSP) con nonce y HSTS.
- Registro de eventos de seguridad en un canal aparte y revisión programada (php artisan security:check).
- Página /admin/seguridad solo para administradores, con confirmación de contraseña.
- Auditoría OWASP Top 10: control de acceso, inyección, CSRF, dependencias y sesiones.
