# Sistema de Gestión de Productos y sus clasificaciones

Proyecto desarrollado con **Laravel 13**, **MySQL**, **Bootstrap 5**, **jQuery**, **DataTables** y estándares de seguridad **OWASP**.

---

## 🏗️ Arquitectura y Tecnologías Principales

- **Backend:** Laravel 13 (PHP 8.3+)
- **Base de Datos:** MySQL (Motor InnoDB, Charset `utf8mb4`, Collation `utf8mb4_unicode_ci`)
- **Frontend / UI:** Bootstrap 5, jQuery, DataTables (con extensiones Responsive y Buttons)
- **Compilador de Assets:** Vite
- **Seguridad y Permisos:** Laravel Sanctum (APIs) + Spatie Laravel-Permission (Roles y Permisos RBAC)
- **Autenticación UI:** vistas Blade propias con Bootstrap 5
- **Exportaciones y Reportes:** Laravel Excel (`maatwebsite/excel`) y DOMPDF (`barryvdh/laravel-dompdf`)
- **Testing:** Pest PHP

---

## 🗄️ Modelo de Datos y Convenciones

1. **Auditoría de tiempos:**
   - Todas las tablas del sistema incluyen campos de fecha y hora:
     - `created_at` (Fecha y hora de registro)
     - `updated_at` (Fecha y hora de última actualización)
2. **Relación Muchos a Muchos (N:M):**
   - Un producto puede poseer múltiples **etiquetas de clasificación**.
   - Una etiqueta de clasificación puede estar presente en múltiples **productos**.
   - Tabla pivote intermedia: `classification_product` con claves foráneas, índices y timestamps.
3. **Formularios (UX y validación):**
   - Las etiquetas de los campos obligatorios llevan un asterisco rojo (`<span class="text-danger">*</span>`), con una leyenda "* Campo obligatorio" al inicio del formulario.
   - Validación en el cliente con atributos HTML5 y estilos de Bootstrap (`needs-validation`, `novalidate`, `was-validated`); el servidor siempre revalida con Form Requests y muestra los errores en rojo (`is-invalid`) con los datos previos (`old()`).
---

## 🗺️ Plan de Acción Paso a Paso

```mermaid
flowchart TD
    F0[Fase 0: Configuración MySQL y Entorno] --> F1[Fase 1: Setup Frontend Vite + Bootstrap + jQuery + DataTables]
    F1 --> F2[Fase 2: CRUD de Clasificaciones/Etiquetas + API + Excel]
    F2 --> F3[Fase 3: Autenticación, Roles Spatie, Sanctum y Dashboards]
    F3 --> F4[Fase 4: CRUD de Productos N:M, Multimedia, Barras y QR]
    F4 --> F5[Fase 5: Reportes PDF, Filtros Avanzados y Excel Productos]
    F5 --> F6[Fase 6: Auditoría de Seguridad OWASP Top 10 y Pruebas Pest]
```

---

### Fase 0: Configuración de Base de Datos MySQL
- [x] Establece las variables de conexión a la base de datos en `.env`:
  ```dotenv
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=proy03armandobs
  DB_USERNAME=root
  DB_PASSWORD=
  ```
- [x] Configura la localización y zona horaria en `.env` y `.env.example` (`config/app.php` lee `APP_TIMEZONE`):
  ```dotenv
  APP_LOCALE=es
  APP_FAKER_LOCALE=es_MX
  APP_TIMEZONE=America/Mexico_City
  ```
- [x] Se verifica el soporte de `utf8mb4_unicode_ci` y motores InnoDB en `config/database.php`.
- [x] Se prueba la conexión y migración inicial del framework.
- [x] Idiomas: Se incluyen mensajes del framework en español en `lang/es/` (`auth`, `pagination`, `passwords`, `validation`) y `lang/es.json`; el inglés viene incluido en Laravel y es el idioma de respaldo (`APP_FALLBACK_LOCALE=en`). Los textos de las vistas se escriben solo en español. Prueba: `tests/Feature/LocalizationTest.php`.

---

### Fase 1: Arquitectura Frontend Base (Bootstrap 5 + jQuery + DataTables)
- [x] Se instalaron dependencias vía npm:
  ```bash
  npm install bootstrap @popperjs/core jquery datatables.net-bs5 datatables.net-buttons-bs5 jszip pdfmake
  ```
- [x] Se configuraron los archivos `resources/js/app.js` y `resources/css/app.css` para importar Bootstrap y exponer `window.$` y `window.jQuery`.
- [x] Se configuró `vite.config.js` y se compilaron los assets iniciales con `npm run build`.
- [x] Se diseñó el layout maestro Blade (`resources/views/layouts/app.blade.php`) con navbar responsiva, contenedor de alertas y secciones `@yield('content')` y `@push('scripts')`.
- [x] Se actualizó la página de bienvenida (`welcome.blade.php`) con Bootstrap 5.
- [x] Script global de validación del cliente en `resources/js/app.js` (formularios con clase `needs-validation`).
- [x] Se generó un componente Blade reutilizable para etiquetas con asterisco de campo obligatorio (`<x-form.label for="name" required>Nombre</x-form.label>`), con prueba en `tests/Feature/Views/FormLabelTest.php`.
- [x] Se aplicó el asterisco y la validación del cliente a los formularios existentes: clasificaciones, importación, login y registro.
- [x] Se cuenta con componentes Blade reutilizables: `<x-form.input>` (etiqueta, campo, error y valor previo) y `<x-auth.card>` (tarjeta centrada de los formularios de acceso).

---

### Fase 2: CRUD de Clasificaciones / Etiquetas (Web, API y Exportación a Excel)
- [x] **Migración y Modelo:**
  - Tabla `classifications` (`id`, `name` único, `slug` indexado, `description`, `color_badge`, `is_active`, `created_at`, `updated_at`).
  - Modelo `App\Models\Classification` con casts, fillables y factory (`ClassificationFactory`).
- [x] **Seeder:**
  - `ClassificationSeeder` con registros manuales y algunos tomados de un catálogo con Faker (usa `SaveClassification`), registrado en `DatabaseSeeder`.
- [x] **Validación:**
  - Form Request único para web, apis y excel `App\Http\Requests\ClassificationRequest` (web, API e importación Excel mediante `baseRules()`; soporta `PUT` y `PATCH` parcial).
- [x] **Acción compartida:**
  - `App\Actions\SaveClassification` (genera el `slug` y guarda); la usan el controlador web, el controlador API y la importación.
- [x] **Exportación a Excel (Instalación):**
  - Se instaló `maatwebsite/excel`.
- [x] **Exportación a Excel (Implementación):**
  - `App\Exports\ClassificationsExport` con encabezados, formato de fechas y estilos de celdas.
  - Endpoint de descarga web: `GET /classifications/export/excel`.
- [x] **Importación desde Excel:**
  - `App\Imports\ClassificationsImport` (`ToModel`, `WithHeadingRow`, `WithValidation`) que reutiliza `ClassificationRequest::baseRules()`, guarda con `SaveClassification`, acepta `is_active` vacío y rechaza nombres duplicados (en BD y dentro del archivo).
  - Endpoint web: `POST /classifications/import` (`.xlsx`/`.csv`, máx. 2 MB), con reporte de errores por fila.
- [x] **Controlador Dual:**
  - Web Controller (`ClassificationController`) con vistas Blade (`resources/views/classifications/`); el listado usa filtros y paginación en servidor (sin DataTables). Requiere sesión iniciada desde la Fase 3.
  - API Controller (`Api\ClassificationController`) con `ClassificationResource` y rutas `apiResource` (`/api/classifications`, nombres `api.classifications.*`). Requiere token de Sanctum desde la Fase 3.
- [x] **Pruebas Automatizadas (Pest):**
  - `tests/Feature/ClassificationControllerTest.php` (CRUD web, exportación e importación), `tests/Feature/Api/ClassificationControllerTest.php` (200, 201, 204, 404, 422, `PUT` y `PATCH`), `ClassificationFilterTest.php` en `tests/Feature/` y `tests/Feature/Api/` (filtros y paginación) y `tests/Feature/ErrorHandlingTest.php`.
- [x] **Cierre del módulo:** manejo centralizado de errores en `bootstrap/app.php` (`QueryException`: 409 por duplicado, 503 por falla de base de datos) y páginas de error personalizadas autónomas (`403`, `404`, `419`, `500`, `503`) en `resources/views/errors/`; la `503` se recarga sola cada 30 segundos (`<meta http-equiv="refresh">`) para recuperarse cuando la base de datos vuelve.
- [x] **Listado con filtros y paginación:**
  - Web y API comparten `ClassificationIndexRequest` (validación de `search`, `is_active` y `per_page`) y el scope `Classification::filter()`.
  - Web: formulario GET con búsqueda por nombre o slug, estado (activas/inactivas) y registros por página (5, 10, 25, 50), con paginación de Laravel (Bootstrap 5) que conserva los filtros.
  - API: parámetros `?search=`, `?is_active=` y `?per_page=` (máx. 100, por defecto 15).

---

### Fase 3: Seguridad, Autenticación y Control de Accesos (RBAC)
- [x] **Autenticación, parte A (vistas Blade propias en Bootstrap 5):**
  - Inicio de sesión (`/login`) con "recordarme" y bloqueo tras 5 intentos fallidos (`Auth\LoginRequest`), registro (`/register`, `Auth\RegisterRequest`) y cierre de sesión (`POST /logout`).
  - Rutas web de clasificaciones protegidas con el middleware `auth`; navbar según la sesión (`@auth` / `@guest`).
  - Destino único tras iniciar sesión o registrarse: la ruta `home` (o la página que el usuario había solicitado); ningún archivo de autenticación nombra un módulo.
  - Las páginas protegidas se envían con `Cache-Control: no-store` para que no se vean con el botón "atrás" tras cerrar sesión.
  - Reglas de nombre, correo y contraseña centralizadas en el trait `Http\Requests\Concerns\UserValidationRules`.
  - Pruebas: `tests/Feature/Auth/LoginTest.php` y `tests/Feature/Auth/RegisterTest.php`.
- [x] **Autenticación, parte B:**
  - Recuperación de contraseña (`/forgot-password` y `/reset-password/{token}`) con el broker de contraseñas de Laravel; la respuesta no revela si el correo está registrado. En local el correo se escribe en `storage/logs/laravel.log` (`MAIL_MAILER=log`) y su texto sale en español (`lang/es.json`).
  - Perfil de usuario (`/profile`): edición de nombre y correo (`ProfileRequest`), y cambio de contraseña con verificación de la contraseña actual (`PasswordUpdateRequest`); ambos reutilizan `UserValidationRules`.
  - Pruebas: `tests/Feature/Auth/PasswordResetTest.php` y `tests/Feature/ProfileTest.php`.
- [x] **Roles y Permisos con Spatie:**
  - `spatie/laravel-permission` instalado; migración de sus tablas y `config/permission.php` publicados; el modelo `User` usa `HasRoles`.
  - Roles (`App\RoleName`): `admin`, `cliente`, `invitado`. Permisos (`App\PermissionName`): `classifications.view`, `.create`, `.update`, `.delete`. Qué permisos tiene cada rol se define en `RoleName::permissions()`.
  - `RolesAndPermissionsSeeder` crea roles y permisos (se puede ejecutar varias veces); `DatabaseSeeder` da el rol `admin` a `test@example.com`. Quien se registra recibe el rol `cliente`.
  - La autorización se declara en las rutas con `App\Http\ResourceAuthorization`, tabla única para web y API: `apply()` protege cada acción de un recurso con el permiso `{recurso}.{habilidad}` y `needs()` protege una ruta suelta (exportar, importar). Controladores y Form Requests no repiten la comprobación. Las instrucciones de uso están en el PHPDoc de la clase; `php artisan route:list --path=classifications -v` muestra el permiso de cada ruta.
  - Las vistas muestran cada acción solo a quien tiene el permiso: `@can(App\PermissionName::CreateClassifications->value)`. Sin permiso, la ruta responde `403`.
  - Las reglas que dependan del registro (por ejemplo, "solo sus propios pedidos") se escribirán en una policy del recurso y se sumarán en la ruta con `->can()`.
  - Pruebas: `ClassificationAuthorizationTest.php` en `tests/Feature/` y `tests/Feature/Api/`; la función `userWithRole()` de `tests/Pest.php` crea usuarios con rol.
- [ ] **Dashboards por Rol:**
  - `/admin/dashboard`: Gestión de usuarios, métricas de inventario y catálogos.
  - `/cliente/dashboard`: Catálogo de productos, carrito/pedidos y perfil.
  - `/invitado/catalogo`: Vista pública de productos sin permisos de edición.
- [x] **Autenticación de la API con Laravel Sanctum (tokens):**
  - Sanctum ya estaba instalado (`php artisan install:api`: `routes/api.php` y migración `personal_access_tokens`); el modelo `User` usa `HasApiTokens`.
  - `POST /api/login` devuelve un token Bearer y los datos del usuario (`UserResource`); reutiliza `Auth\LoginRequest`, con el mismo bloqueo tras 5 intentos fallidos que la web. `POST /api/logout` revoca solo el token usado. `GET /api/user` devuelve el usuario del token.
  - Los endpoints de clasificaciones exigen `auth:sanctum`; sin token responden `401` en JSON.
  - Pruebas: `tests/Feature/Api/TokenControllerTest.php`; las pruebas de clasificaciones de la API se autentican con `Sanctum::actingAs`.
- [x] **Autorización de la API por rol:**
  - La API de clasificaciones usa la misma tabla `ResourceAuthorization` que la web; sin permiso responde `403` en JSON. `GET /api/user` incluye los roles del usuario.
- [ ] **Endurecimiento de la API:**
  - Caducidad de los tokens (`sanctum.expiration`) y límite de peticiones (`throttle`) de la API.

---

### Fase 4: CRUD de Productos (Relación N:M, Multimedia y Códigos)
- [ ] **Migraciones y Modelos:**
  - Tabla `products` (`id`, `name`, `slug`, `description`, `photo_path`, `barcode`, `qr_code`, `stock`, `purchase_price`, `sale_price`, `is_active`, `created_at`, `updated_at`).
  - Tabla pivote `classification_product` (`id`, `product_id`, `classification_id`, `created_at`, `updated_at`) con claves foráneas en cascada e índices compuestos.
  - Relaciones Eloquent `belongsToMany` en `Product` y `Classification`.
- [ ] **Carga Segura de Imágenes:**
  - Validación de subida (MIME types reales: jpeg, png, webp; tamaño máx; almacenamiento seguro en `storage/app/public/products`).
- [ ] **Generación de Códigos:**
  - Generación de Código de Barras (EAN-13 o Code 128) y Código QR dinámico.
- [ ] **DataTables de Productos:**
  - Vista con listado de etiquetas tipo badges, foto en miniatura, búsqueda y paginación.

---

### Fase 5: Reportes, Filtros Avanzados y Exportaciones
- [ ] **Exportación de Productos a Excel:**
  - `ProductsExport` con desglose de etiquetas asociadas, stock, precios y fechas de auditoría.
- [ ] **Reportes en PDF:**
  - Integrar `barryvdh/laravel-dompdf`.
  - Reporte de catálogo completo en PDF y ficha técnica individual por producto con código de barras y QR.
- [ ] **DataTables Server-Side:**
  - Búsqueda y filtrado por rangos de precio, stock y etiquetas múltiples con optimización de consultas SQL (evitando problemas N+1 con `with('classifications')`).

---

### Fase 6: Auditoría de Seguridad OWASP Top 10 y Cierre
- [ ] **A01: Broken Access Control:** Policies de Laravel para cada recurso y validación estricta de propiedad de datos.
- [ ] **A02: Cryptographic Failures:** Contraseñas hasheadas (Bcrypt cost 12), variables sensibles ocultas en `.env`.
- [ ] **A03: Injection:** Prepared statements con Eloquent, validaciones en FormRequests y escape estricto en Blade.
- [ ] **A04: Insecure Design:** Rate limiting con `throttle` en autenticación y APIs para prevenir ataques de fuerza bruta.
- [ ] **A05: Security Misconfiguration:** Cabeceras HTTP de seguridad (`X-Frame-Options`, `X-Content-Type-Options`, `CSP`, `Referrer-Policy`).
- [ ] **A06: Vulnerable Components:** Auditoría periódica con `composer audit` y `npm audit`.
- [ ] **A07: Identification Failures:** Expiración de sesiones, reglas fuertes de contraseña y protección contra fijación de sesión.
- [ ] **A08: Software and Data Integrity:** Validación estricta de archivos subidos por extensión y magic bytes.
- [ ] **A09: Security Logging:** Registro en log de intentos de acceso no autorizados (403/401) y eventos críticos.
- [ ] **Protección CSRF:** Tokens `@csrf` en formularios web y cabecera `X-XSRF-TOKEN` en peticiones AJAX.

---

## 🚀 Guía de Inicio Rápido (Entorno Local)

1. Clonar el repositorio y configurar variables:
   ```bash
   cp .env.example .env
   # Configurar credenciales de MySQL en .env
   ```
2. Instalar dependencias de PHP y generar clave:
   ```bash
   composer install
   php artisan key:generate
   ```
3. Ejecutar migraciones y seeders:
   ```bash
   php artisan migrate --seed
   ```
4. Instalar y compilar dependencias frontend:
   ```bash
   npm install
   npm run build
   ```
5. Iniciar servidores de desarrollo:
   ```bash
   composer run dev
   # o en terminales separadas:
   # php artisan serve
   # npm run dev
   ```


---

## 🔌 Uso de la API

Todas las peticiones llevan la cabecera `Accept: application/json`.

1. Obtener un token (el seeder crea `test@example.com` / `password`):
   ```bash
   curl -X POST http://localhost:8000/api/login -H "Accept: application/json" -d "email=test@example.com" -d "password=password"
   ```
   Respuesta: `{"data": {"id": 1, "name": "...", "email": "...", "roles": ["admin"]}, "token": "1|abc...", "token_type": "Bearer"}`.
2. Usar el token en cada petición:
   ```bash
   curl http://localhost:8000/api/classifications -H "Accept: application/json" -H "Authorization: Bearer 1|abc..."
   ```
3. Cerrar sesión (revoca ese token):
   ```bash
   curl -X POST http://localhost:8000/api/logout -H "Accept: application/json" -H "Authorization: Bearer 1|abc..."
   ```

| Respuesta | Significado |
|---|---|
| `401` | Falta el token, es inválido o fue revocado. |
| `403` | El token es válido, pero el rol del usuario no tiene permiso para esa acción. |
| `422` | Credenciales incorrectas, datos inválidos o demasiados intentos de acceso. |

---

## 🛡️ Roles y permisos

| Rol | Clasificaciones |
|---|---|
| `admin` | Ver, crear, editar, eliminar, importar y exportar. |
| `cliente` | Ver y exportar. Es el rol de quien se registra. |
| `invitado` | Sin acceso. |

Un usuario sin rol tampoco tiene acceso. Importar requiere el permiso de crear; exportar, el de ver.

1. Crear las tablas de Spatie y los roles en una base de datos existente (no borra datos):
   ```bash
   php artisan migrate
   ```
   ```bash
   php artisan db:seed --class=RolesAndPermissionsSeeder
   ```
2. Dar un rol a un usuario que ya existía (los usuarios anteriores a este cambio no tienen ninguno):
   ```bash
   php artisan tinker --execute 'App\Models\User::where("email", "test@example.com")->firstOrFail()->assignRole("admin");'
   ```
3. Tras cambiar `RoleName::permissions()`, volver a ejecutar el seeder del paso 1.

Para añadir un permiso: nuevo caso en `App\PermissionName`, asignarlo en `RoleName::permissions()`, exigirlo en la ruta con `ResourceAuthorization::needs()` y ejecutar el seeder.

---

## ✉️ Correo: prueba local y envío real

La aplicación envía un solo correo: el enlace para restablecer la contraseña (`/forgot-password`).

### Prueba local (sin enviar nada)

Con `MAIL_MAILER=log` el correo no sale; se escribe en `storage/logs/laravel.log`.

1. Verificar en `.env`:
   ```dotenv
   MAIL_MAILER=log
   ```
2. Tener un usuario registrado. El seeder crea `test@example.com` con contraseña `password`:
   ```bash
   php artisan migrate:fresh --seed
   ```
3. Abrir `/forgot-password`, escribir el correo y pulsar "Enviar enlace".
4. Abrir `storage/logs/laravel.log` y buscar al final la línea con `/reset-password/`.
5. Copiar ese enlace en el navegador, escribir la contraseña nueva e iniciar sesión con ella.

Notas:
- El enlace caduca en 60 minutos y solo sirve una vez.
- Solo se genera un enlace por correo cada 60 segundos; un segundo intento inmediato no escribe nada en el log.
- La pantalla muestra el mismo aviso exista o no la cuenta, así que si no aparece nada en el log, revisar que el correo esté registrado.
- En el log el enlace puede salir partido en varias líneas (cada una termina en `=`) y con `=3D` en lugar de `=`. Al copiarlo hay que unir las líneas quitando ese `=` final y cambiar `=3D` por `=`.

### Envío real (SMTP)

1. Conseguir los datos SMTP del proveedor (servidor, puerto, usuario y contraseña).
2. Configurar `.env` (nunca `.env.example` ni el repositorio):
   ```dotenv
   MAIL_MAILER=smtp
   MAIL_SCHEME=null
   MAIL_HOST=smtp.proveedor.com
   MAIL_PORT=587
   MAIL_USERNAME=usuario
   MAIL_PASSWORD=contraseña
   MAIL_FROM_ADDRESS="no-responder@midominio.com"
   MAIL_FROM_NAME="${APP_NAME}"
   ```
   Con el puerto 465 usar `MAIL_SCHEME=smtps`.
3. Poner en `APP_URL` la dirección real del sitio y en `APP_NAME` el nombre que verá el destinatario.
4. Recargar la configuración:
   ```bash
   php artisan config:clear
   ```
5. Enviar un correo de prueba:
   ```bash
   php artisan tinker --execute 'Mail::raw("Prueba de envío", fn ($m) => $m->to("destinatario@example.com")->subject("Prueba"));'
   ```
6. Si llega, repetir el flujo de `/forgot-password` con una cuenta cuyo correo exista de verdad.

Notas:
- **Gmail:** servidor `smtp.gmail.com`, puerto `587`, usuario la cuenta completa y como contraseña una "contraseña de aplicación" (requiere verificación en dos pasos); la contraseña normal no funciona.
- El correo se envía dentro de la misma petición, así que no hace falta `php artisan queue:work`.
- Si falla, el error queda en `storage/logs/laravel.log`.
- En producción conviene un proveedor de correo transaccional y configurar SPF y DKIM en el dominio para no caer en spam.
