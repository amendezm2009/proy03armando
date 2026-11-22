# Registro de cambios

Todos los cambios relevantes de la aplicación. Formato basado en Keep a Changelog; las versiones siguen SemVer (MAYOR.MENOR.PARCHE).

## [1.0.2] - 2026-11-21

### Añadido
- Se realiza el upload de la rama de forma automática despues de publicar

## [1.0.1] - 2026-11-21

### Añadido
- Implementa la acción PrepareFirstInstall para la configuración inicial de la aplicación
- Completar funcionalidades de versionado y despliegue
- Versionado, empaquetado para release,  health check, y signing keys
- Se agregó el dashboard para los diferentes roles admin cliente e invitado - Se Implementó la paginación en los dashboards y en el catálogo de productos.
- Se implementó la gestión de productos integrando librerias para código de barras de los productos picqer/php-barcode-generator, bacon/bacon-qr-code y james-heinrich/getid3 (este ultimo para analiza...
- Se implementa la funcionalidad del upload del avatar. - se agregan los archivos AvatarController para manipulación de las acciones vía API y web. - se crea AvatarFileController para servir las imag...
- Se solicita confirmación de password para operarciones sensibles
- Se agrega el módulo de gestión de usuarios. - un usuario no puede cambiar su propio rol, tampoco puede desactivarse. - Se valida el proceso de invitación y se envía la notificación apropiada. - se ...
- Se implementa confirmación de password en editar perfil, se emplea rate limiting y hashing con Argon2id (escalando a bcrypt)
- Se implementa el control de acceso basado en roles demostrando la aplicación en el módulo de clasificaciones.
- Se implementó la autenticación de APIs con Laravel Sanctum,incluyendo login, protegiendo las apis de logout, datos del usuario y las apis de clasificaciones debiendo autenticarse con la api/login p...
- Se implementan las funcionalidades de recuperación de password y editar perfil
- Se implementa la traducción en español para la autenticación, validaciones de inputs, y mensajes de paginación. refactor(auth): se centralizan las reglas de validación de usuarios en un trait refac...
- Se implementa la autenticación del usuario con login y registro, se realiza la protección de funcionalidades de vistas (pendiente proteger a apis según roles)
- Se implementan pruebas para el  ClassificationController y para los errores personalizados 403, 404, 419, 500, and 503
- Se agrega la API de Classification con operaciones de CRUD; se agrega la lib de Sanctum authentication; se guarda la config de la extensión de Bruno como API client (opencollection.yml y classifica...
- Implement CRUD operations, import/export functionality, and localization support
- Add bootstrap, jquery, datatables, and maatwebsite/excel dependencies with initial views and layouts

### Cambiado
- Implementación de filtros y paginación en el módulo de clasificaciones tanto en la vista como en la api.
- Creación de controlador, vistas y validaciones para el módulo de Clasificaciones
- Archivos preparados para el modelo Classification, migration y factory
- Add design and UI skill guides
- Estructura inicial del proyecto

### Corregido
- Se agrega meta refresh para la página  503 de error y se actualiza su layout para incluir la head section, con el fin de recuperar la página cuando la base de datos vuelve.

### Seguridad
- Se implementan características de seguridad incluyendo CSP, HSTS, y reporte de securidad.
- Se implementa las medidas de seguridad para las rutas de productos y exposición de datos.
- Se implementa token expiration y request rate limiting para la ejecución de las APIs, separando los intentos para login, registro de usuarios y recuperación de passwords para aumentar la seguridad

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
