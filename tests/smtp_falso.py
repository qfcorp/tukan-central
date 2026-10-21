#!/usr/bin/env python3
"""Servidor SMTP DE MENTIRA para las pruebas de compartido/tukan_correo.php (sin librerias externas).

  python3 tests/smtp_falso.py --puerto 8256 --modo starttls --cert c.pem --key k.pem --usuario u --clave c --dir CARPETA

Modos: starttls (ofrece STARTTLS y exige TLS antes de AUTH), ssl (TLS desde el inicio), plano (sin TLS).
Guarda cada mensaje en CARPETA/N.eml y una linea JSON por conversacion en CARPETA/registro.jsonl
(lo que se recibio, si habia TLS al entrar, el usuario y si la clave era la correcta; la clave NO se guarda).
Escribe "LISTO" en la salida cuando ya escucha.
"""
import argparse, base64, json, os, socket, socketserver, ssl, sys, threading

p = argparse.ArgumentParser()
p.add_argument('--puerto', type=int, required=True)
p.add_argument('--modo', choices=['starttls', 'ssl', 'plano'], default='starttls')
p.add_argument('--cert'); p.add_argument('--key')
p.add_argument('--usuario', default='info@tukansoftware.com'); p.add_argument('--clave', default='clave')
p.add_argument('--dir', required=True)
p.add_argument('--sin-auth-login', action='store_true', help='solo ofrece AUTH PLAIN')
p.add_argument('--mudo', action='store_true', help='acepta la conexion y no contesta (para probar el tiempo agotado)')
a = p.parse_args()
os.makedirs(a.dir, exist_ok=True)
ctx = None
if a.modo != 'plano':
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(a.cert, a.key)
contador = [0]
candado = threading.Lock()


class Conversacion(socketserver.BaseRequestHandler):
    def setup(self):
        self.s = self.request
        self.buf = b''
        self.tls = False
        self.reg = {'tls_al_entrar': None, 'usuario': None, 'auth_ok': False, 'mail_from': None, 'rcpt': [], 'archivos': [], 'ordenes': []}

    def linea(self):
        while b'\r\n' not in self.buf:
            d = self.s.recv(65536)
            if not d:
                raise EOFError
            self.buf += d
        l, self.buf = self.buf.split(b'\r\n', 1)
        return l

    def decir(self, t):
        self.s.sendall(t.encode() + b'\r\n')

    def ehlo(self):
        ext = ['250-tukan-falso hola']
        if a.modo == 'starttls' and not self.tls:
            ext.append('250-STARTTLS')
        ext.append('250-AUTH PLAIN' if a.sin_auth_login else '250-AUTH LOGIN PLAIN')
        ext.append('250 8BITMIME')
        self.decir('\r\n'.join(ext))

    def entrar(self, usuario, clave):
        self.reg['tls_al_entrar'] = self.tls
        self.reg['usuario'] = usuario
        ok = usuario == a.usuario and clave == a.clave
        self.reg['auth_ok'] = ok
        self.decir('235 2.7.0 Autenticado' if ok else '535 5.7.8 Error: authentication failed')
        return ok

    def handle(self):
        if a.mudo:
            try:
                self.s.recv(10)
            except Exception:
                pass
            return
        try:
            if a.modo == 'ssl':
                self.s = ctx.wrap_socket(self.s, server_side=True)
                self.tls = True
            self.decir('220 tukan-falso ESMTP listo')
            autenticado = False
            while True:
                l = self.linea().decode('utf-8', 'replace')
                cmd = l.split(' ', 1)[0].upper()
                self.reg['ordenes'].append(cmd if cmd in ('AUTH',) else l[:200])
                if cmd in ('EHLO', 'HELO'):
                    self.ehlo()
                elif cmd == 'STARTTLS' and a.modo == 'starttls' and not self.tls:
                    self.decir('220 2.0.0 Listo para TLS')
                    self.s = ctx.wrap_socket(self.s, server_side=True)
                    self.buf = b''
                    self.tls = True
                elif cmd == 'AUTH':
                    if a.modo == 'starttls' and not self.tls:
                        self.decir('530 5.7.0 Primero STARTTLS'); continue
                    partes = l.split(' ')
                    mec = partes[1].upper() if len(partes) > 1 else ''
                    if mec == 'LOGIN' and not a.sin_auth_login:
                        self.decir('334 VXNlcm5hbWU6')
                        u = base64.b64decode(self.linea()).decode('utf-8', 'replace')
                        self.decir('334 UGFzc3dvcmQ6')
                        c = base64.b64decode(self.linea()).decode('utf-8', 'replace')
                        autenticado = self.entrar(u, c)
                    elif mec == 'PLAIN':
                        dato = partes[2] if len(partes) > 2 else self.linea().decode()
                        _, u, c = base64.b64decode(dato).decode('utf-8', 'replace').split('\0')
                        autenticado = self.entrar(u, c)
                    else:
                        self.decir('504 5.5.4 Mecanismo no soportado')
                elif cmd == 'MAIL':
                    if not autenticado:
                        self.decir('530 5.7.0 Autenticacion requerida'); continue
                    self.reg['mail_from'] = l[10:].strip()
                    self.decir('250 2.1.0 Ok')
                elif cmd == 'RCPT':
                    self.reg['rcpt'].append(l[8:].strip())
                    self.decir('250 2.1.5 Ok')
                elif cmd == 'DATA':
                    self.decir('354 Termine con <CRLF>.<CRLF>')
                    datos = []
                    while True:
                        x = self.linea()
                        if x == b'.':
                            break
                        datos.append(x[1:] if x.startswith(b'..') else x)
                    with candado:
                        contador[0] += 1
                        n = contador[0]
                    f = os.path.join(a.dir, f'{n}.eml')
                    with open(f, 'wb') as h:
                        h.write(b'\r\n'.join(datos) + b'\r\n')
                    self.reg['archivos'].append(f)
                    self.decir('250 2.0.0 Ok: en cola')
                elif cmd == 'QUIT':
                    self.decir('221 2.0.0 Chao')
                    break
                elif cmd in ('RSET', 'NOOP'):
                    self.decir('250 Ok')
                else:
                    self.decir('502 5.5.2 Orden desconocida')
        except (EOFError, ConnectionError, ssl.SSLError, OSError):
            pass
        finally:
            with candado:
                with open(os.path.join(a.dir, 'registro.jsonl'), 'a') as h:
                    h.write(json.dumps(self.reg) + '\n')


class Servidor(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


srv = Servidor(('127.0.0.1', a.puerto), Conversacion)
print('LISTO', flush=True)
srv.serve_forever()
