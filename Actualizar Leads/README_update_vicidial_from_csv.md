# Documentación — `update_vicidial_from_csv.php`

Esta guía explica, de forma práctica y exhaustiva, cómo usar, adaptar y operar el **script PHP** que **lee un CSV** y **actualiza/inserta leads en VICIdial** ajustando **prioridad (`priority`)** y, opcionalmente, **empujando los registros al hopper** de una campaña.

> **Objetivo:** permitir cargas masivas seguras y auditablemente trazables en VICIdial, evitando duplicados por teléfono/código de proveedor, controlando la prioridad de marcación y (si se desea) alimentando el hopper para discado inmediato.

---

## 1) Requisitos previos

- **PHP CLI** ≥ 7.4 con **cURL** habilitado.
- **Acceso HTTPS** al endpoint `non_agent_api.php` de tu servidor VICIdial.
- **Usuario API** en `vicidial_users` con permisos para Non-Agent API:
  - `modify_leads = 1`
  - `user_level >= 8` (recomendado)
- **CSV con encabezados**, en **UTF‑8** (idealmente **sin BOM**) y separador de coma.
- **Listas y campañas** previamente creadas (si vas a insertar cuando no exista el lead y/o empujar al hopper).

---

## 2) ¿Qué hace el script? (Resumen de flujo)

1. **Abre** el CSV y **lee** la fila de **encabezados**.
2. **Itera** cada fila de datos:
   - **Normaliza** `phone_number` (deja solo dígitos).
   - Determina **`priority`**, **`status`** y **`list_id`** (usando el CSV o valores por defecto).
   - **Construye** la llamada `GET` hacia `non_agent_api.php?function=update_lead` con parámetros de matching flexible, inserción condicional y actualización de teléfono.
   - Si está **habilitado**, añade `add_to_hopper=Y` + `campaign_id` (y opcionalmente `entry_list_id`).
3. **Invoca** la API y **clasifica** la respuesta como **OK/ERROR/SKIP/DRY‑RUN**.
4. **Aplica rate‑limit** entre requests para no sobrecargar DB.
5. **Imprime un resumen** y **muestra hasta 20 resultados** de detalle.

---

## 3) Uso básico

```bash
php update_vicidial_from_csv.php ruta/al/archivo.csv
```

### Modo simulación (sin escribir en VICIdial)

```bash
php update_vicidial_from_csv.php ruta/al/archivo.csv --dry-run
```
- No se ejecutan llamadas reales; se muestran las **URLs** que **se habrían** llamado.
- Útil para **validar encabezados**, `list_id`, `priority`, `status` y reglas de matching.

---

## 4) Configuración principal (constantes)

En la sección **CONFIGURACIÓN** del script ajusta:

- `API_URL` — URL HTTPS hacia `non_agent_api.php` (p. ej. `https://tu-servidor/vicidial/non_agent_api.php`).
- `API_USER` / `API_PASS` — credenciales del **usuario API** de VICIdial.
- `API_SOURCE` — etiqueta (≤ 20 chars) para auditar el origen (por ejemplo `CSV_UPD`).
- `DEFAULT_SEARCH_METHOD` — cómo buscar el lead existente (por defecto `PHONE_NUMBER_VENDOR_LEAD_CODE` para flexibilizar matching por **teléfono** y/o **vendor_lead_code**).
- `DEFAULT_SEARCH_LOCATION` — dónde buscar (`SYSTEM`|`LIST`|`CAMPAIGN`); `SYSTEM` busca globalmente.
- `INSERT_IF_NOT_FOUND` — `'Y'` insertará si no encuentra; requiere **`list_id`**.
- `UPDATE_PHONE_NUMBER` — `'Y'` permite sobreescribir `phone_number` si cambia.
- `DEFAULT_STATUS` — estado por defecto si no viene en CSV (p. ej. `NEW`).
- `DEFAULT_LIST_ID` — forzar una lista cuando el CSV no traiga `list_id`.
- `DEFAULT_PRIORITY` — prioridad por defecto (0–99). **99** = prioridad muy alta.
- `PUSH_TO_HOPPER` — `true`/`false` para empujar al hopper tras actualizar.
- `HOPPER_CAMPAIGN_ID` — campaña destino del hopper (si `PUSH_TO_HOPPER`).
- `RATE_LIMIT_MS` — milisegundos de pausa entre requests (p. ej. `150`).

> **Sugerencia:** versiona este archivo y maneja **.env** o variables de entorno para credenciales en ambientes productivos.

---

## 5) Encabezados y mapeo de campos

El script trae una **allowlist** de campos VICIdial comunes (`$ALLOWLIST_FIELDS`). Si un encabezado del CSV **coincide** con uno de esos nombres, el valor **se pasa** a la API. Campos clave:

- Identificadores y contacto: `lead_id`, `vendor_lead_code`, `source_id`, `list_id`, `phone_number`, `phone_code`, `alt_phone`, `email`
- Datos personales: `title`, `first_name`, `middle_initial`, `last_name`, `date_of_birth`
- Dirección: `address1`, `address2`, `address3`, `city`, `state`, `province`, `postal_code`, `country_code`
- Gestión: `status`, `comments`, `security_phrase`, `rank`, `owner`, `user`, `entry_date`, `gmt_offset_now`, `called_since_last_reset`, `entry_list_id`
- **Priorización**: `priority` (0–99)

### Reglas importantes
- **`phone_number`** se **normaliza** a dígitos; si queda vacío, **se omite** la fila.
- Si `INSERT_IF_NOT_FOUND='Y'` y **no hay `list_id`**, la fila se **omite** (SKIP).
- Si el CSV **no trae `priority`**, se usa `DEFAULT_PRIORITY` y se **acota** a `0..99`.
- Si `PUSH_TO_HOPPER = true`, se envía `add_to_hopper=Y` + `campaign_id` y, si existe, `entry_list_id = list_id`.

---

## 6) Matching, inserción y hopper

### Matching (`search_method`)
- Por defecto: `PHONE_NUMBER_VENDOR_LEAD_CODE` (busca por **teléfono** y/o **vendor_lead_code**).
- Alternativas comunes: `LEAD_ID` | `PHONE_NUMBER` | `VENDOR_LEAD_CODE`

### Inserción condicional
- `insert_if_not_found='Y'` **crea** el lead si no existe **(requiere `list_id`)**.
- Si quieres **no** crear nuevos, usa `'N'` (solo actualizar existentes).

### Hopper
- `add_to_hopper='Y'` + `campaign_id='CAMP01'` empuja al **hopper** de esa **campaña**.
- **`priority`** influye en el **orden** de marcación (0 bajo, 99 alto).
- `entry_list_id` ayuda a mantener trazabilidad de la lista de origen.

---

## 7) Manejo de errores y salidas

Cada fila genera una entrada en `results` con:
- `status`: `OK` | `ERROR` | `SKIP` | `DRY-RUN`
- `line`: número de línea del CSV
- `detail`: texto de respuesta o motivo

Al final imprime un **RESUMEN** en **JSON**:
```json
{
  "archivo": "ruta/al/archivo.csv",
  "dry_run": false,
  "ok": 123,
  "errores": 4,
  "omitidos": 7
}
```

Y luego lista hasta **20** resultados línea por línea, por ejemplo:
```
[OK] línea 2 => SUCCESS: 1
[ERROR] línea 5 => ERROR: invalid list_id
[SKIP] línea 9 => Sin phone_number
```

### Errores típicos y cómo resolverlos
- **`ERROR: You are not allowed to use non-agent api functions`**  
  Revisa permisos del **usuario API** (`vicidial_users`): activar **Non‑Agent API** y `user_level` suficiente.
- **`ERROR: invalid list_id` / `List not active`**  
  Confirma que la **lista existe** y está **activa**. Si insertas, debes **enviar `list_id`**.
- **`ERROR: campaign_id is invalid`** (al empujar al hopper)  
  Verifica `HOPPER_CAMPAIGN_ID` y que la **campaña esté activa**.
- **Sin teléfono**  
  La fila se omite si `phone_number` vacío tras normalizar.
- **`HTTP 4xx/5xx`**  
  Problemas de red/SSL/autenticación. Revisa `API_URL`, certificados y credenciales.

---

## 8) Buenas prácticas operativas

- **Prueba con `--dry-run`** antes de ejecutar en producción.
- **Valida el CSV**: encabezados correctos, `list_id` presente si vas a insertar, **teléfonos** con al menos **7 dígitos**.
- **Usa `RATE_LIMIT_MS`** para cuidar el motor de base de datos (ej. 100–250 ms).
- **Control de cambios**: versiona el script y conserva los CSV procesados y resúmenes JSON para auditoría.
- **Seguridad**: evita hardcodear credenciales; usa variables de entorno o un `.env` fuera del VCS.
- **Idempotencia**: define claramente `search_method` y `insert_if_not_found` para que re‑correr el CSV sea seguro.

---

## 9) Ejemplo de CSV (mínimo viable)

> Guarda un archivo como `ejemplo.csv` con encabezados compatibles:

```csv
vendor_lead_code,phone_number,phone_code,first_name,last_name,list_id,status,priority,comments,email
A-0001,5551234567,52,María,Pérez,1001,NEW,95,Cliente VIP,maria.perez@example.com
A-0002,5557654321,52,Juan,López,1001,,80,Seguir mañana,juan.lopez@example.com
A-0003,55-58-77-22-11,52,Ana,García,1002,CBHOLD,99,Promesa de pago,ana.garcia@example.com
```

> En este ejemplo:
> - La 3ª fila trae `phone_number` con guiones; el script lo **normaliza** a dígitos.
> - La 2ª fila no trae `status`; el script usa `DEFAULT_STATUS`.
> - Las prioridades se acotan a `0..99`.

---

## 10) Ejemplos de ejecución

1) **Simulación previa (sin tocar VICIdial):**
```bash
php update_vicidial_from_csv.php ejemplo.csv --dry-run
```

2) **Ejecución real con inserción y hopper activados:**
```bash
php update_vicidial_from_csv.php ejemplo.csv
```

3) **Forzando una `list_id` por defecto en el script** (si tu CSV no la trae):
```php
const DEFAULT_LIST_ID = '1001';
const INSERT_IF_NOT_FOUND = 'Y';
```
> Si una fila no trae `list_id`, usará `1001`.

---

## 11) Extensiones y personalización

- **Más campos**: agrega nombres válidos a `$ALLOWLIST_FIELDS` si tu flujo los requiere.
- **Logs a archivo**: además del `echo`, escribe `results` y `summary` en un `.log` o `.json` con `file_put_contents()`.
- **Validación avanzada**: agrega chequeos (ej. longitud de teléfono, formato de email, estados válidos por campaña).
- **Métricas**: mide QPS, latencias y errores por tipo para dimensionar `RATE_LIMIT_MS`.
- **Modo batch**: procesa múltiples CSVs en un directorio y consolida un reporte final.

---

## 12) FAQ

**¿Necesito `list_id` en el CSV?**  
- Solo si `insert_if_not_found='Y'`. Para **actualizar** leads existentes no es estrictamente necesario, pero es recomendable por trazabilidad.

**¿Qué prioridad debo usar?**  
- `0..30` baja, `31..69` media, `70..89` alta, `90..99` muy alta. Adáptalo a tu operación.

**¿El hopper es obligatorio?**  
- No. Si tu operación usa reciclajes o marcación programada, puedes desactivar `PUSH_TO_HOPPER`.

**¿Puedo buscar por `lead_id`?**  
- Sí. Cambia `DEFAULT_SEARCH_METHOD='LEAD_ID'` y envía `lead_id` en el CSV.

---

## 13) Estructura del código (mapa rápido)

- **CONFIGURACIÓN**: constantes y allowlist.
- **ENTRADA**: parsing de argumentos, `--dry-run`, validaciones.
- **UTILIDADES**: `normalizePhone`, `curlGet`, `buildApiUrl`, `pick`, `sleepMs`.
- **PROCESAR CSV**: lectura de encabezados, bucle por filas, armado de parámetros, llamada a API, clasificación de respuesta, rate‑limit.
- **RESUMEN**: impresión de métricas y primeros 20 resultados.

---

## 14) Checklist antes de producción

- [ ] `API_URL`, `API_USER`, `API_PASS` correctos y **con HTTPS** válido.
- [ ] Usuario API con **Non‑Agent API** autorizado y `user_level` suficiente.
- [ ] `DEFAULT_SEARCH_METHOD`/`LOCATION` adecuados a tu operación.
- [ ] `INSERT_IF_NOT_FOUND` coherente con tu CSV y `list_id` disponible.
- [ ] `PUSH_TO_HOPPER` y `HOPPER_CAMPAIGN_ID` correctos si usarás hopper.
- [ ] CSV validado (encabezados, `phone_number`, `list_id`, `status`, `priority`).
- [ ] Prueba **`--dry-run`** y revisa cuidadosamente el output.
- [ ] Define y documenta **quién** ejecuta y **dónde** quedan los **logs**.

---

## 15) Licencia y mantenimiento

Este script se ofrece como base operativa para tu flujo en VICIdial. Ajusta, versiona y audita cambios conforme tus políticas internas de TI y compliance.
