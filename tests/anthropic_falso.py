"""API de Anthropic DE MENTIRA (POST /v1/messages) para las pruebas de compartido/tukan_ia.php y deploy/configurar_ia.sh.
Uso: python3 tests/anthropic_falso.py PUERTO ARCHIVO_REGISTRO
Clave buena: la variable CLAVE_BUENA (por defecto sk-ant-prueba-buena-0123456789). Otra clave -> 401.
Clave sk-ant-sin-saldo-... -> 400 "Your credit balance is too low".
Marcas en el texto del usuario:
  FORZAR_429:N      las primeras N veces con ese mismo texto -> 429 (retry-after: 0); despues responde bien
  FORZAR_529        siempre 529 (overloaded)
  JSON_MALO_UNA_VEZ la primera respuesta no es JSON; si el pedido ya trae la correccion, responde {"ok": true}
  RESPONDER:texto   responde exactamente "texto" (hasta el final del bloque)
  TOKENS:e/s        usage input_tokens=e output_tokens=s
Imagenes: responde "IMAGEN <media_type> <bytes>". Ayuda (system con "asistente de ayuda"): pasos con los titulos del <manual>.
Cada pedido queda en ARCHIVO_REGISTRO (una linea JSON; de la clave solo si era la buena, nunca la clave).
"""
import base64, json, os, re, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PUERTO = int(sys.argv[1])
REGISTRO = sys.argv[2]
CLAVE_BUENA = os.environ.get('CLAVE_BUENA', 'sk-ant-prueba-buena-0123456789')
cuentas = {}
candado = threading.Lock()


def textos(mensajes):
    r = []
    for m in mensajes:
        c = m.get('content')
        if isinstance(c, str):
            r.append(c)
        else:
            for b in c or []:
                if b.get('type') == 'text':
                    r.append(b.get('text', ''))
    return '\n'.join(r)


class Manejador(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def responder(self, codigo, cuerpo, cab=None):
        datos = json.dumps(cuerpo, ensure_ascii=False).encode()
        self.send_response(codigo)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(datos)))
        for k, v in (cab or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(datos)

    def error(self, codigo, tipo, msj, cab=None):
        self.responder(codigo, {'type': 'error', 'error': {'type': tipo, 'message': msj}}, cab)

    def do_POST(self):
        largo = int(self.headers.get('Content-Length') or 0)
        crudo = self.rfile.read(largo)
        clave = self.headers.get('x-api-key', '')
        try:
            p = json.loads(crudo)
        except Exception:
            p = {}
        mensajes = p.get('messages') or []
        texto = textos(mensajes)
        imagenes = [b for m in mensajes if isinstance(m.get('content'), list) for b in m['content'] if b.get('type') in ('image', 'document')]
        reg = {'ruta': self.path, 'version': self.headers.get('anthropic-version'), 'tipo': self.headers.get('content-type'),
               'clave_ok': clave == CLAVE_BUENA, 'clave_larga': len(clave), 'modelo': p.get('model'), 'max_tokens': p.get('max_tokens'),
               'system': p.get('system', ''), 'texto': texto, 'n_mensajes': len(mensajes),
               'imagenes': [b['source']['media_type'] for b in imagenes]}

        def fin(codigo):
            reg['http'] = codigo
            with candado:
                with open(REGISTRO, 'a') as f:
                    f.write(json.dumps(reg, ensure_ascii=False) + '\n')

        if self.path != '/v1/messages':
            fin(404); return self.error(404, 'not_found_error', 'Not found')
        if clave.startswith('sk-ant-sin-saldo'):
            fin(400); return self.error(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.')
        if clave != CLAVE_BUENA:
            fin(401); return self.error(401, 'authentication_error', 'invalid x-api-key')
        if self.headers.get('anthropic-version') != '2023-06-01':
            fin(400); return self.error(400, 'invalid_request_error', 'anthropic-version header is required')
        if p.get('model') == 'modelo-inexistente':
            fin(404); return self.error(404, 'not_found_error', 'model: modelo-inexistente')
        m = re.search(r'FORZAR_429:(\d+)', texto)
        if m:
            with candado:
                n = cuentas.get(texto, 0)
                cuentas[texto] = n + 1
            if n < int(m.group(1)):
                fin(429); return self.error(429, 'rate_limit_error', 'Number of request tokens has exceeded your per-minute rate limit', {'retry-after': '0'})
        if 'FORZAR_529' in texto:
            fin(529); return self.error(529, 'overloaded_error', 'Overloaded')

        if 'JSON_MALO_UNA_VEZ' in texto:
            salida = '{"ok": true, "corregido": true}' if 'no fue un JSON' in texto else 'Claro, aqui va: no es json'
        elif 'RESPONDER:' in texto:
            salida = texto.split('RESPONDER:', 1)[1].split('\n</')[0].strip()
        elif imagenes:
            b = imagenes[0]['source']
            salida = 'IMAGEN %s %d' % (b['media_type'], len(base64.b64decode(b['data'])))
        elif 'asistente de ayuda' in str(p.get('system', '')):
            titulos = re.findall(r'^## (.+)$', texto, re.M)
            salida = '1. Abra el menu.\n2. Siga lo que dice: ' + ' | '.join(titulos) + '.'
        else:
            salida = 'OK'
        m = re.search(r'TOKENS:(\d+)/(\d+)', texto)
        ent, sal = (int(m.group(1)), int(m.group(2))) if m else (max(1, len(crudo) // 4), max(1, len(salida) // 4))
        fin(200)
        self.responder(200, {'id': 'msg_prueba', 'type': 'message', 'role': 'assistant', 'model': p.get('model'),
                             'content': [{'type': 'text', 'text': salida}], 'stop_reason': 'end_turn',
                             'usage': {'input_tokens': ent, 'output_tokens': sal}})


ThreadingHTTPServer(('127.0.0.1', PUERTO), Manejador).serve_forever()
