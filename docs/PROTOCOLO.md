# Protocolo Tukán Central v1

Contrato único entre el **Panel Tukán Central** (panel.tukanec.com) y cada programa de la familia Tukán
(TuEvento, TuSalón, TuVocalía, TuAcademia, TuGuardería y los que vengan). El ERP QF NO participa.

Cada programa instalado es una **instancia** (TuGuardería puede tener varias: tuguarderia, dogssy, huellitas…).
Cada instancia tiene su propio **ID** y su propio **secreto**. El panel central guarda los dos; la instancia
los tiene en su configuración.

## 1. Configuración en cada instancia

Variables de entorno (o el archivo de configuración que ya use el programa, con estos mismos nombres):

| Variable | Ejemplo | Qué es |
|---|---|---|
| `TUKAN_CENTRAL_ID` | `tuevento` | ID de la instancia (minúsculas, números, `-` y `_`, 2 a 40) |
| `TUKAN_CENTRAL_SECRET` | 64 caracteres hexadecimales | Secreto compartido (lo genera el panel central) |
| `TUKAN_CENTRAL_URL` | `https://panel.tukanec.com` | Para el botón "Volver al Panel Tukán" |
| `TUKAN_SOLO_CENTRAL` | `0` o `1` | Opcional. `1` = se apaga el ingreso local del super usuario y solo se entra desde el panel central (el ingreso de los clientes NO cambia) |

Si `TUKAN_CENTRAL_ID` o `TUKAN_CENTRAL_SECRET` faltan (o el secreto tiene menos de 32 caracteres), el conector
está **apagado**: `tukan_central.php` responde 404 y todo sigue como antes.

## 2. Un solo archivo en cada programa: `/tukan_central.php`

Cada programa expone, en la raíz pública de su sitio, el archivo **`tukan_central.php`**:

- `GET /tukan_central.php?accion=sso&t=<token>` → entra como super usuario y redirige al panel de administración del programa.
- `GET /tukan_central.php?accion=estado&t=<token>` → devuelve JSON con las cifras del programa.

## 3. Token

```
token = b64url(payload_json) + "." + b64url(HMAC_SHA256(b64url(payload_json), SECRET))
```
- `b64url` = base64 URL-safe **sin** relleno `=`.
- La firma se calcula sobre el **texto** `b64url(payload_json)` (la primera parte, tal cual viaja).
- La clave del HMAC es el secreto **tal como está escrito** (los 64 caracteres hex como texto, no decodificados).

`payload_json`:
```json
{"v":1,"tipo":"sso","inst":"tuevento","email":"dimitryfierro@gmail.com","nombre":"Dimitry","iat":1760371200,"exp":1760371260,"nonce":"32 hex"}
```
- `tipo`: `"sso"` o `"estado"`.
- `exp - iat` ≤ 60 segundos.

La instancia **valida** (en este orden; si algo falla: sso → página de error simple en español con enlace al panel central y código 401; estado → `401 {"ok":false,"error":"..."}`):
1. Dos partes separadas por `.`; firma correcta con `hash_equals`.
2. `v == 1`, `tipo` igual a la acción pedida, `inst == TUKAN_CENTRAL_ID`.
3. Tiempo: `ahora ≤ exp + 30` y `iat ≤ ahora + 30` (30 s de tolerancia de reloj) y `exp - iat ≤ 60`.
4. `nonce` de 32 hex que **no se haya usado antes** (se guarda con su `exp`; se borran los vencidos). Un token sirve una sola vez.
5. `email` válido.
6. Límite: máximo 30 pedidos por minuto por IP a `tukan_central.php`.

## 4. SSO (`accion=sso`)
- Busca el super usuario del programa con ese `email`. Si no existe, lo **crea** (nombre del token, contraseña aleatoria
  imposible de adivinar, marcado como creado por el panel central si la tabla lo permite).
  En programas de una sola empresa (TuGuardería), entra con el usuario administrador principal de esa instalación
  (o crea un administrador con ese email).
- Inicia la sesión de super usuario exactamente como lo hace el ingreso normal (regenerar el id de sesión).
- Deja constancia en el registro/auditoría del programa: "Entró desde el Panel Tukán Central".
- Redirige al inicio del panel de administración del programa.
- En ese panel aparece un enlace **"← Panel Tukán"** a `TUKAN_CENTRAL_URL` cuando la sesión vino del central.

## 5. Estado (`accion=estado`)
Respuesta `200 application/json` (solo lectura, sin datos personales salvo nombres de negocios):
```json
{
  "ok": true,
  "protocolo": 1,
  "producto": "tuevento",
  "nombre": "TuEvento",
  "version": "75df6b3",
  "generado": "2026-10-13T12:00:00-05:00",
  "negocios": {"total": 12, "prueba": 4, "activos": 6, "por_vencer": 1, "vencidos": 1, "suspendidos": 0},
  "nuevos_30d": 3,
  "ingresos_mes": 414.00,
  "ingresos_mes_anterior": 276.00,
  "pruebas_vencen_7d": [{"nombre": "Quinta Los Alamos", "vence": "2026-10-17"}],
  "pagos_vencidos": [{"nombre": "Salón Imperial", "desde": "2026-10-01"}],
  "extra": {"Eventos este mes": 37, "Usuarios": 41}
}
```
- Cualquier campo que el programa no tenga va en `null` (o lista vacía). `extra` es libre (máximo 8 pares texto → número o texto).
- Programas de una sola empresa (TuGuardería): `negocios` = `null`; usan `extra` (ej. "Mascotas activas", "Reservas este mes", "Usuarios") y agregan `"cliente": "Dogssy"`.
- `version`: commit corto de git si se puede leer (archivo `.git/HEAD`), o un texto de versión.
- Listas con máximo 20 elementos.

## 6. Pruebas que debe tener cada conector
Token correcto entra; firma mala, otro `inst`, `tipo` cambiado, vencido, futuro, nonce repetido, sin configurar (404),
email inválido → rechazados. Estado devuelve JSON válido con las claves de arriba. El ingreso de clientes no cambia.
Con `TUKAN_SOLO_CENTRAL=1` el ingreso local del super usuario queda apagado con un mensaje claro.

## 7. Ejemplo de referencia (PHP)
```php
function tc_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function tc_unb64(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }
function tc_firmar(array $p, string $secreto): string {
    $a = tc_b64(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $a . '.' . tc_b64(hash_hmac('sha256', $a, $secreto, true));
}
```
