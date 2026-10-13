"""Capturas de pantalla del Panel Tukán Central con Playwright (Chromium), a 390 px (celular) y en escritorio.
Las llama tests/run_web.sh con el panel y los programas de prueba ya corriendo.
Uso: BASE=http://127.0.0.1:8301 python3 tests/capturas.py CARPETA_SALIDA
Falla (código 1) si hay errores de JavaScript o de la política de seguridad (CSP) en la consola.
"""
import base64, hashlib, hmac, os, struct, sys, time
from playwright.sync_api import sync_playwright

BASE = os.environ.get('BASE', 'http://127.0.0.1:8301')
SALIDA = sys.argv[1] if len(sys.argv) > 1 else 'tests/capturas'
EMAIL, CLAVE = 'capturas@tukan.ec', 'claveCapturas2026'
os.makedirs(SALIDA, exist_ok=True)


def totp(secreto, paso=None):
    clave = base64.b32decode(secreto.replace(' ', '').upper() + '=' * (-len(secreto.replace(' ', '')) % 8))
    paso = int(time.time()) // 30 if paso is None else paso
    h = hmac.new(clave, struct.pack('>Q', paso), hashlib.sha1).digest()
    o = h[-1] & 0x0F
    return '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


errores, hechas = [], []
with sync_playwright() as p:
    nav = p.chromium.launch()
    ctx = nav.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, locale='es-EC')
    pag = ctx.new_page()
    pag.on('console', lambda m: errores.append(f'{pag.url}: {m.text}') if m.type == 'error' else None)
    pag.on('pageerror', lambda e: errores.append(f'{pag.url}: {e}'))

    def foto(nombre, completa=True):
        for ancho, alto, sufijo in ((390, 844, '390'), (1280, 860, 'escritorio')):
            pag.set_viewport_size({'width': ancho, 'height': alto})
            pag.wait_for_timeout(150)
            ruta = os.path.join(SALIDA, f'{nombre}_{sufijo}.png')
            pag.screenshot(path=ruta, full_page=completa)
            hechas.append(os.path.basename(ruta))
        pag.set_viewport_size({'width': 390, 'height': 844})

    pag.goto(f'{BASE}/?r=login')
    foto('1_login')
    pag.fill('#email', EMAIL)
    pag.fill('#clave', CLAVE)
    pag.click('button[type=submit]')
    pag.wait_for_url('**r=activar_2fa**')
    foto('2_activar_2fa')
    secreto = pag.inner_text('[data-clave]')
    pag.fill('#codigo', totp(secreto))          # app.js lo envía solo al completar 6 dígitos
    pag.wait_for_url('**r=codigos**')
    foto('3_codigos_respaldo')
    pag.click('form[action*="codigos"] button[type=submit]')
    pag.wait_for_url('**r=inicio**')
    pag.wait_for_load_state('networkidle')
    if pag.get_attribute('#tablero', 'data-viejo') == '1':   # app.js actualiza las cifras y recarga
        pag.wait_for_timeout(6500)
        pag.wait_for_load_state('networkidle')
    foto('4_inicio')
    pag.goto(f'{BASE}/?r=programa_nuevo')
    pag.check('input[value=tuguarderia]', force=True)
    pag.fill('#id', 'huellitas')
    pag.fill('#url', 'https://huellitas.tukanec.com')
    foto('5_agregar_programa')
    pag.click('form#form-programa button[type=submit]')
    pag.wait_for_url('**r=programa_comando**')
    foto('6_comando')
    pag.goto(f'{BASE}/?r=programa&id=tuevento')
    foto('7_ficha_programa')
    pag.goto(f'{BASE}/?r=auditoria')
    foto('8_auditoria', completa=False)
    pag.goto(f'{BASE}/?r=ayuda')
    foto('9_ayuda', completa=False)
    nav.close()

print(f'{len(hechas)} capturas en {SALIDA}')
if errores:
    print('ERRORES EN LA CONSOLA:')
    for e in errores:
        print('  ' + e)
    sys.exit(1)
