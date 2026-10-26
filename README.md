# API de notificaciones push (CodeIgniter 4 + FCM HTTP v1)

API multi-organización para **registrar tokens FCM** de usuarios y **enviar notificaciones push** a usuarios, oficinas, roles o a toda una organización.

- PHP 8.2+ · CodeIgniter 4.7 · MySQL 8 / MariaDB 10.6+ (o PostgreSQL)
- Firebase Cloud Messaging **HTTP v1** (la API "legacy" fue retirada por Google en 2024)

---

## 1. Arquitectura

```
 Sistemas de cada organización (backend)
        │  HTTPS + API key (Authorization: Bearer pk_...)
        ▼
 ┌──────────────── Balanceador / Nginx (TLS) ─────────────────┐
 │                                                           │
 │  API CI4 (N nodos, sin estado)                            │
 │   Filtros: invalidchars → jsonbody → apiauth → secureheaders
 │   ├─ POST   /api/v1/tokens            registrar token     │
 │   ├─ DELETE /api/v1/tokens            baja (logout)       │
 │   ├─ POST   /api/v1/notificaciones    enviar              │
 │   ├─ GET    /api/v1/notificaciones/id estado del envío    │
 │   └─ GET    /api/v1/health            health check        │
 └───────────────┬───────────────────────────┬───────────────┘
                 │                           │
          ┌──────▼──────┐             ┌──────▼──────┐
          │  MySQL      │◄────────────┤  Redis      │ rate limit, caché de
          │  api_clients│  cola       │  (caché)    │ llaves y access token
          │  fcm_tokens │  (tabla     └─────────────┘ de Google
          │  notificac. │  notificaciones)
          └──────▲──────┘
                 │ reclama trabajos (UPDATE atómico)
          ┌──────┴──────────────┐      curl_multi (50 conexiones
          │ Workers             │      HTTP/2 en paralelo)
          │ php spark push:worker├─────────────────────────────► FCM HTTP v1
          └─────────────────────┘                                (web/Android/iOS)
```

**Capas (estilo "controlador delgado → servicio → modelo"):**

| Capa | Archivos | Responsabilidad |
|---|---|---|
| Rutas | `app/Config/Routes.php` | Versionado `/api/v1`, filtro y scope por ruta |
| Filtros | `app/Filters/ApiAuth.php`, `JsonBody.php` | Autenticación, autorización por scope, IP allowlist, rate limit, tamaño/forma del cuerpo |
| Controladores | `app/Controllers/Api/V1/*` | Validación de entrada y formato de respuesta |
| Servicio de dominio | `app/Libraries/NotificationDispatcher.php` | Resolver destinatarios, enviar por lotes, contabilizar, dar de baja tokens inválidos |
| Integración FCM | `app/Libraries/Fcm/*` | OAuth2 con cuenta de servicio (JWT RS256), envío paralelo, reintentos |
| Modelos | `app/Models/*` | Acceso a datos, consultas indexadas, cola |
| CLI | `app/Commands/*` | Worker, alta de API keys, purga de tokens |

**Flujo de envío**

1. El backend de la organización llama `POST /api/v1/notificaciones`.
2. Se valida, se cuenta a cuántos tokens activos llega y se guarda el registro en `notificaciones`.
3. Si son ≤ `push.syncLimit` (200 por defecto) se envía en la misma petición → **200** con el resultado.
   Si son más (o `"asincrono": true`) → **202** con el `id`; un worker lo procesa.
4. El worker pagina los tokens por cursor (`id > n`) en lotes de 500, envía cada lote con 50 peticiones simultáneas, guarda el avance tras cada lote y desactiva los tokens que FCM reporta como `UNREGISTERED`.
5. El cliente consulta `GET /api/v1/notificaciones/{id}`.

---

## 2. Base de datos: ¿Firebase o relacional?

**Recomendación: MySQL/MariaDB (o PostgreSQL) para el registro de tokens, y Firebase solo para FCM.**

Aunque "solo se registran usuarios con su token", la API necesita en cada envío consultas como *"tokens activos de la organización ROMA, oficina OF01, rol SUPERVISOR"*:

| Criterio | MySQL / PostgreSQL | Firestore / Realtime DB |
|---|---|---|
| Integración con CI4 | Nativa (Query Builder, migraciones, modelos, tests) | No hay driver; requiere `google/cloud-firestore` + **extensión gRPC** de PHP (compleja de instalar) o la API REST |
| Consultas por organización + oficina + rol + usuario | Índices compuestos, `IN (...)`, unión de criterios en una sola consulta | `in` limitado a 30 valores, sin `OR` entre campos distintos sin índices compuestos; varias consultas y unión en PHP |
| Recorrer 100 000 tokens para un envío masivo | Paginación por cursor, barata | Cada documento leído se cobra |
| Transacciones / unicidad del token | Índice único `token_hash` + transacción | Se emula con el ID del documento |
| Latencia desde PHP | Red local (<1 ms) | HTTPS a Google en cada consulta |
| Costo | Fijo | Por lectura/escritura |
| Bitácora / cola de envíos | Misma BD | Habría que usar otro almacenamiento |

Firestore tiene sentido cuando **la app cliente** lee/escribe directamente con el SDK y reglas de seguridad. Aquí quien consulta es un backend PHP, así que un relacional es más simple, más rápido y más barato. Si en el futuro se quisiera Firestore, solo cambiaría `FcmTokenModel` (el resto depende de sus métodos).

### Tablas

**`fcm_tokens`** — la tabla de registro solicitada

| Campo | Tipo | Notas |
|---|---|---|
| id | BIGINT PK | |
| organizacion | CHAR(4) | Siempre la de la API key (no se confía en el cliente) |
| oficina | CHAR(4) | Mayúsculas, `[A-Z0-9]{4}` |
| usuario | VARCHAR(190) NULL | Login (opcional; el usuario se identifica por email) |
| nombre_usuario | VARCHAR(150) | |
| email | VARCHAR(190) | |
| rol | VARCHAR(30) NULL | Necesario para enviar por rol |
| token | VARCHAR(4096) | Token FCM |
| token_hash | CHAR(64) UNIQUE | SHA-256 del token (índice único compacto) |
| navegador | VARCHAR(100) NULL | |
| dispositivo | VARCHAR(100) NULL | web / android / ios / modelo |
| activo | TINYINT | Reservado (los tokens inválidos se borran) |
| motivo_baja | VARCHAR(50) NULL | Reservado |
| fecha_registro | DATETIME | Primera vez que se registró |
| fecha_actualizacion | DATETIME | Último re-registro (FCM recomienda refrescar el token periódicamente) |
| ultimo_envio | DATETIME NULL | Último envío exitoso |

Índices: `(organizacion, usuario, activo)`, `(organizacion, oficina, activo)`, `(organizacion, rol, activo)`.

**`api_clients`** — llaves de API (solo se guarda el hash). **`notificaciones`** — cola + auditoría de cada envío.

> Un token identifica un **dispositivo**: si se registra un token que ya existía con otro usuario, se reasigna al nuevo (es quien inició sesión en ese navegador/teléfono).

---

## 3. Seguridad

| Riesgo | Medida |
|---|---|
| Acceso no autorizado | API key por organización `pk_<prefijo>.<secreto>` (256 bits). En BD solo el SHA-256; comparación en tiempo constante (`hash_equals`). Se muestra una única vez al crearla. |
| Una organización envía/lee datos de otra (multi-tenant) | La organización sale **de la llave**, no del cuerpo. Si el cuerpo pide otra → 403. Todas las consultas filtran por `organizacion`. El estado de un envío de otra organización responde 404. |
| Privilegio mínimo | Scopes por llave: `tokens` (registrar/baja) y `notificaciones` (enviar/consultar). Ej.: una app solo con `tokens` no puede enviar. |
| Llave robada | Allowlist de IPs/CIDR por llave (`allowed_ips`), revocación (`activo = 0`, efectiva en ≤ 60 s), `ultimo_uso` para detectar llaves sin uso. |
| Fuerza bruta de llaves | Bloqueo por IP tras 20 intentos fallidos/minuto (429 + `Retry-After`) sin consultar la BD. |
| Abuso / DoS | Rate limit por cliente (token bucket, `rate_limit` por minuto), cuerpo máx. 64 KB, máx. 1000 elementos por lista, mensaje ≤ 4 KB. |
| Inyección SQL | Query Builder con parámetros enlazados; entradas validadas con listas blancas de caracteres (`regex_match`). |
| XSS / phishing vía notificación | `url` e `imagen` solo **https** (`valid_url_strict[https]`); se eliminan etiquetas HTML de nombres; claves reservadas de FCM en `data` rechazadas. |
| Reenvíos duplicados por reintentos del cliente | Cabecera `Idempotency-Key` (única por cliente). |
| Transporte | Forzar HTTPS en producción (`app.forceGlobalSecureRequests`), cabeceras `SecureHeaders`, `Cache-Control: no-store`, `nosniff`. |
| Credenciales de Firebase | JSON de la cuenta de servicio fuera de `public/`, permisos 0400, ruta por `.env`. Access token OAuth en caché 55 min; nunca se registra en logs. |
| Filtrado de información | Errores genéricos; en `production` CI4 no muestra trazas. Los logs registran `token_id`, nunca el token. |
| Tokens viejos | Se **borran** cuando FCM responde `UNREGISTERED`, al cerrar sesión (`DELETE /tokens`) y con `push:purge` (sin actualizar en 270 días); máximo 10 dispositivos por email (se borran los más antiguos). |

**Importante:** las API keys son secretos de servidor. El navegador/app del usuario **no** debe llamar a esta API directamente con una llave: el flujo recomendado es
`app cliente → su backend (ya autenticó al usuario) → esta API`. Si se necesita llamar desde un front, cree una llave solo con scope `tokens`.

---

## 4. Escalabilidad

- **API sin estado**: se puede escalar horizontalmente detrás de un balanceador. El estado compartido vive en MySQL y Redis (configure `cache.handler = redis` para que rate limit y caché sean globales).
- **Envíos grandes fuera de la petición HTTP**: cola en la tabla `notificaciones`; varios workers en paralelo (en uno o varios servidores). Cada trabajo se reclama con un `UPDATE` condicionado, por lo que dos workers nunca toman el mismo.
- **Tolerancia a caídas**: el worker guarda el cursor tras cada lote; si muere, otro retoma el trabajo tras `staleJobSeconds` (entrega "al menos una vez").
- **Throughput hacia FCM**: la API v1 es un mensaje por token, así que se envían 50 peticiones simultáneas con `curl_multi` sobre HTTP/2. Orientativamente ~300-800 mensajes/s por worker; con 4 workers, 100 000 tokens en pocos minutos. La cuota por defecto de FCM es 600 000 mensajes/minuto por proyecto.
- **Reintentos**: 429/5xx/errores de red con backoff exponencial + jitter; 401 renueva el access token.
- **Consultas**: índices compuestos que empiezan por `organizacion`; paginación por `id` (no `OFFSET`), coste constante aunque la tabla tenga millones de filas.
- **Escrituras mínimas**: `ultimo_uso` de la llave se actualiza como máximo una vez por minuto; la llave validada se cachea 60 s.
- Si el volumen crece mucho (millones/día), la tabla-cola puede sustituirse por Redis Streams / RabbitMQ / Pub/Sub sin tocar los controladores (solo `NotificacionModel::reclamarSiguiente` y el worker).

---

## 5. Instalación

```bash
composer install --no-dev --optimize-autoloader
cp env .env                # y edite BD, Redis, push.serviceAccountPath, CI_ENVIRONMENT=production
php spark migrate

# Crear una llave para la organización ROMA (se muestra una sola vez)
php spark apikey:create "Sistema ROMA" --org ROMA --ips 203.0.113.10
```

**Cuenta de servicio de Firebase:** consola de Firebase → Configuración del proyecto → Cuentas de servicio → *Generar nueva clave privada*. Guarde el JSON fuera del proyecto y configure `push.serviceAccountPath`.

**Worker** (systemd o supervisor, uno o varios):

```ini
[program:push-worker]
command=php /var/www/push/spark push:worker --max-jobs 500
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autorestart=true
stopsignal=TERM
user=www-data
```

Alternativa sin supervisor (cron cada minuto): `* * * * * php /var/www/push/spark push:worker --once`

**Purga diaria:** `0 3 * * * php /var/www/push/spark push:purge`

Nginx debe apuntar el document root a `public/`.

---

## 6. Endpoints

Autenticación en todas (excepto health): `Authorization: Bearer pk_xxxxxxxxxxxx.yyyy…` (o `X-API-Key`). Cuerpo `Content-Type: application/json`.

### `POST /api/v1/tokens` — registrar token (scope `tokens`)

```json
{
  "oficina": "OF01",
  "nombre_usuario": "Juan Pérez",
  "email": "jperez@roma.com",
  "rol": "SUPERVISOR",
  "token": "fcm-token-del-dispositivo...",
  "navegador": "Chrome 130",
  "dispositivo": "web"
}
```

`organizacion` se toma de la llave; solo las llaves globales (`--global`) deben enviarla.
Respuesta `201` (nuevo) o `200` (actualizado):

```json
{ "status": 201, "data": { "id": 15, "organizacion": "ROMA", "oficina": "OF01", "email": "jperez@roma.com", "registrado": true } }
```

Llame a este endpoint al iniciar sesión y cada vez que el SDK de Firebase entregue un token nuevo (`onTokenRefresh` / `getToken`).

### `DELETE /api/v1/tokens` — baja (scope `tokens`)

```json
{ "token": "fcm-token-del-dispositivo..." }
```

Llamar al cerrar sesión. `200` o `404`.

### `POST /api/v1/notificaciones` — enviar (scope `notificaciones`)

```json
{
  "destinatarios": {
    "emails": ["jperez@roma.com", "mlopez@roma.com"],
    "oficinas": ["0001"],
    "roles": ["SUPERVISOR"],
    "todos": false
  },
  "notificacion": {
    "titulo": "Nuevo expediente",
    "cuerpo": "Se le asignó el expediente 1234",
    "imagen": "https://cdn.roma.com/logo.png",
    "url": "https://app.roma.com/expedientes/1234"
  },
  "data": { "tipo": "expediente", "id": "1234" },
  "prioridad": "high",
  "ttl": 3600,
  "asincrono": false
}
```

Reglas de destinatarios (siempre dentro de la organización de la llave):

- `"todos": true` → todos los usuarios de la organización (companies).
- `emails` → esas personas (todos sus dispositivos). También se acepta `usuarios` (login).
- `oficinas` (branches) y/o `roles` → segmento; si vienen ambos se combinan con **Y** (rol SUPERVISOR **de** la oficina 0001).
- `emails` + segmento → **unión** de ambos.

**Imagen:** `notificacion.imagen` es una URL **https** pública (JPG/PNG, idealmente < 1 MB). FCM descarga la imagen; no se adjunta el archivo. Se envía para Android, web e iOS (en iOS la app necesita una *Notification Service Extension* para mostrarla).

Cabecera opcional `Idempotency-Key: <8-100 caracteres>` para reintentar sin duplicar envíos.

Respuesta `200` (enviado ya) o `202` (encolado):

```json
{
  "status": 200,
  "data": {
    "id": "6f1c1f0e-8a8a-4b63-9a3e-2b7f0e4b3a11",
    "organizacion": "ROMA",
    "estado": "completado",
    "total": 3, "exitosos": 2, "fallidos": 0, "invalidos": 1,
    "creado": "2026-10-23 10:00:00", "completado": "2026-10-23 10:00:01"
  }
}
```

`invalidos` = tokens que FCM rechazó como caducados (se desactivan automáticamente).

### `POST /api/v1/notificaciones/lote` — varios mensajes personalizados (scope `notificaciones`)

Hasta 100 mensajes por petición; cada uno con sus propios destinatarios, título, cuerpo, imagen y `data`. Se validan todos antes de enviar: si uno tiene errores no se envía ninguno (los errores vienen como `mensajes.N.campo`).

```json
{
  "mensajes": [
    {
      "referencia": "pedido-981",
      "destinatarios": { "emails": ["ana@roma.com"] },
      "notificacion": { "titulo": "Hola Ana", "cuerpo": "Tu pedido 981 salió", "imagen": "https://cdn.roma.com/pedidos/981.jpg" }
    },
    {
      "destinatarios": { "oficinas": ["0002"] },
      "notificacion": { "titulo": "Oficina 0002", "cuerpo": "Reunión a las 10:00" }
    },
    {
      "destinatarios": { "todos": true },
      "notificacion": { "titulo": "Aviso general", "cuerpo": "Mañana no hay servicio", "imagen": "https://cdn.roma.com/aviso.png" }
    }
  ]
}
```

Respuesta: `resumen` con los totales y `data` con el resultado de cada mensaje (`indice`, `referencia`, `id`, `estado`, `exitosos`...). Si el lote completo supera `push.syncLimit` tokens, todo se encola (202) y cada `id` se consulta con el endpoint de estado. `Idempotency-Key` (8-90 caracteres) aplica al lote completo.

### `GET /api/v1/notificaciones/{id}` — estado (scope `notificaciones`)

Estados: `pendiente`, `procesando`, `completado`, `fallido`.

### Códigos de error

`400` validación (`messages` con el detalle por campo) · `401` llave inválida · `403` scope, IP u organización no permitida · `404` · `413` cuerpo muy grande · `415` no es JSON · `429` rate limit (ver `Retry-After`).

### Ejemplo cURL

```bash
curl -X POST https://push.tu-dominio.com/api/v1/notificaciones \
  -H "Authorization: Bearer $PUSH_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: aviso-2026-10-23-001" \
  -d '{"destinatarios":{"oficinas":["OF01"]},"notificacion":{"titulo":"Aviso","cuerpo":"Reunión a las 10:00"}}'
```

---

## 7. Pruebas

```bash
composer install
vendor/bin/phpunit
```

Las pruebas usan SQLite en memoria y un cliente FCM falso (`tests/_support/Fcm/FakeFcmClient.php`): autenticación, scopes, IPs, aislamiento entre organizaciones, validaciones, límite de dispositivos, segmentación por usuario/oficina/rol, baja de tokens inválidos, idempotencia y cola con worker.
