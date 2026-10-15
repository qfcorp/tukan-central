"""Capturas de pantalla del Panel Tukán Central con Playwright (Chromium), a 390 px (celular) y en escritorio.
Las llama tests/run_web.sh con el panel y los programas de prueba ya corriendo.
Uso: BASE=http://127.0.0.1:8301 python3 tests/capturas.py CARPETA_SALIDA
Falla (código 1) si hay errores de JavaScript o de la política de seguridad (CSP) en la consola,
o si alguna pantalla se sale a lo ancho (scroll horizontal).
"""
import base64, hashlib, hmac, os, struct, sys, time
from playwright.sync_api import sync_playwright

BASE = os.environ.get('BASE', 'http://127.0.0.1:8260')
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
            pag.evaluate('window.scrollTo(0, 0)')
            pag.wait_for_timeout(150)
            ancho_doc = pag.evaluate('document.documentElement.scrollWidth')
            if ancho_doc > ancho:
                errores.append(f'{nombre} a {ancho}px: se sale de la pantalla (ancho {ancho_doc}px)')
            ruta = os.path.join(SALIDA, f'{nombre}_{sufijo}.png')
            # En la captura de página completa, la barra fija de abajo (celular) se pone al final, como se ve al bajar
            # (con el CSSOM: la CSP no permite estilos en línea)
            if completa:
                pag.evaluate("document.querySelectorAll('.nav-abajo').forEach(n => n.style.position = 'static')")
            pag.screenshot(path=ruta, full_page=completa)
            if completa:
                pag.evaluate("document.querySelectorAll('.nav-abajo').forEach(n => n.style.position = '')")
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
    foto('9_ayuda')
    # Protocolo v2
    def ir(ruta, nombre, completa=True):
        pag.goto(f'{BASE}/?r={ruta}')
        pag.wait_for_load_state('networkidle')
        foto(nombre, completa)
    ir('inicio', '10_inicio_v2')
    ir('solicitudes', '11_solicitudes')
    ir('solicitud&i=tuvocalia&id=soporte:9', '12_solicitud_detalle')
    # La hoja con el formulario (se abre con JavaScript)
    for ancho, alto, sufijo in ((390, 844, '390'), (1280, 860, 'escritorio')):
        pag.set_viewport_size({'width': ancho, 'height': alto})
        pag.goto(f'{BASE}/?r=cliente&i=tuvocalia&id=6')
        pag.wait_for_load_state('networkidle')
        pag.click('a[data-hoja][href*="a=registrar_pago"]')
        pag.wait_for_selector('#hoja[open] form#form-accion')
        pag.wait_for_timeout(700)
        pag.screenshot(path=os.path.join(SALIDA, f'13_hoja_registrar_pago_{sufijo}.png'))
        hechas.append(f'13_hoja_registrar_pago_{sufijo}.png')
        pag.keyboard.press('Escape')
    pag.set_viewport_size({'width': 390, 'height': 844})
    ir('clientes&vence=30', '14_clientes_filtro')
    ir('cliente&i=tuvocalia&id=6', '15_cliente_ficha')
    ir('demo_nueva&i=tuvocalia', '16_demo_formulario')
    pag.fill('#d-nombre', 'Liga Barrial El Inca')
    pag.fill('#d-contacto_nombre', 'Mario Paredes')
    pag.fill('#d-telefono', '0991112233')
    pag.fill('#d-email', 'mario@elinca.ec')
    pag.click('form#form-demo button[type=submit]')
    pag.wait_for_load_state('networkidle')
    foto('17_demo_lista')
    ir('finanzas', '18_finanzas')
    ir('gastos', '19_gastos_formulario')
    ir('vencimientos', '20_vencimientos')
    ir('embudo', '21_embudo')
    ir('demos', '22_demos')
    ir('mas', '23_mas')
    # Anexo A: pausar y borrar clientes (lista con los botones, papelera, fichas y las hojas de los formularios)
    ir('clientes', '24_clientes_pausar_borrar')
    ir('clientes&estado=papelera', '25_clientes_papelera')
    ir('cliente&i=tuvocalia&id=7', '26_ficha_pausada')
    ir('cliente&i=tuvocalia&id=2', '27_ficha_papelera')
    for accion, cliente, nombre in (('pausar', '3', '28_hoja_pausar'), ('borrar_cliente', '3', '29_hoja_borrar')):
        for ancho, alto, sufijo in ((390, 844, '390'), (1280, 860, 'escritorio')):
            pag.set_viewport_size({'width': ancho, 'height': alto})
            pag.goto(f'{BASE}/?r=clientes')
            pag.wait_for_load_state('networkidle')
            pag.click(f'a[data-hoja][href*="i=tuvocalia&a={accion}&cliente={cliente}&"]')
            pag.wait_for_selector('#hoja[open] form#form-accion')
            pag.wait_for_timeout(700)
            ancho_doc = pag.evaluate('document.documentElement.scrollWidth')
            if ancho_doc > ancho:
                errores.append(f'{nombre} a {ancho}px: se sale de la pantalla (ancho {ancho_doc}px)')
            pag.screenshot(path=os.path.join(SALIDA, f'{nombre}_{sufijo}.png'))
            hechas.append(f'{nombre}_{sufijo}.png')
            pag.keyboard.press('Escape')
    pag.set_viewport_size({'width': 390, 'height': 844})
    # Pausar / Borrar cliente desde Solicitudes, menú ⋯ de los programas y zona de peligro sin conexión
    ir('solicitudes&chip=recordatorios', '30_solicitudes_pausar_borrar')
    ir('programa&id=dogssy', '31_programa_sin_conexion')

    def parte(selector, nombre):
        for ancho, alto, sufijo in ((390, 844, '390'), (1280, 860, 'escritorio')):
            pag.set_viewport_size({'width': ancho, 'height': alto})
            pag.wait_for_timeout(150)
            ancho_doc = pag.evaluate('document.documentElement.scrollWidth')
            if ancho_doc > ancho:
                errores.append(f'{nombre} a {ancho}px: se sale de la pantalla (ancho {ancho_doc}px)')
            pag.evaluate("document.querySelectorAll('.barra, .nav-abajo').forEach(n => n.style.position = 'static')")
            pag.locator(selector).first.screenshot(path=os.path.join(SALIDA, f'{nombre}_{sufijo}.png'))
            pag.evaluate("document.querySelectorAll('.barra, .nav-abajo').forEach(n => n.style.position = '')")
            hechas.append(f'{nombre}_{sufijo}.png')
        pag.set_viewport_size({'width': 390, 'height': 844})

    parte('#baja', '32_zona_peligro_sin_conexion')
    pag.goto(f'{BASE}/?r=solicitudes&chip=recordatorios')
    pag.wait_for_load_state('networkidle')
    parte('article.sol:has(.btn-pausar)', '33_tarjeta_no_pago')
    parte('article.sol:has(.sol-acc-2)', '34_tarjeta_fila_secundaria')
    pag.goto(f'{BASE}/?r=inicio')
    pag.wait_for_load_state('networkidle')
    pag.click('article.prog[data-inst="dogssy"] .menu-prog summary')
    parte('article.prog[data-inst="dogssy"]', '35_inicio_menu_programa')
    for ancho, alto, sufijo in ((390, 844, '390'), (1280, 860, 'escritorio')):
        pag.set_viewport_size({'width': ancho, 'height': alto})
        pag.goto(f'{BASE}/?r=solicitudes&chip=recordatorios')
        pag.wait_for_load_state('networkidle')
        pag.click('a[data-hoja].btn-peligro[href*="a=borrar_cliente"][href*="de_sol="]')
        pag.wait_for_selector('#hoja[open] form#form-accion')
        pag.wait_for_timeout(700)
        pag.screenshot(path=os.path.join(SALIDA, f'36_hoja_borrar_desde_solicitud_{sufijo}.png'))
        hechas.append(f'36_hoja_borrar_desde_solicitud_{sufijo}.png')
        pag.keyboard.press('Escape')
    pag.set_viewport_size({'width': 390, 'height': 844})
    nav.close()

print(f'{len(hechas)} capturas en {SALIDA}')
if errores:
    print('ERRORES EN LA CONSOLA:')
    for e in errores:
        print('  ' + e)
    sys.exit(1)
