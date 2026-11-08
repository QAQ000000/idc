#!/usr/bin/env python3
"""Local single-message SMTP recipient, with explicit rejection and lost DATA response modes."""
import http.server
import json
import socketserver
import sys
import threading

state = {'mode': 'normal', 'messages': [], 'data_attempts': 0}
lock = threading.Lock()


class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(15)
        self.wfile.write(b'220 fixture ESMTP\r\n')
        recipient = ''
        while True:
            line = self.rfile.readline(16384)
            if not line:
                return
            command = line.upper()
            if command.startswith(b'EHLO'):
                self.wfile.write(b'250-fixture\r\n250 8BITMIME\r\n')
            elif command.startswith(b'HELO') or command.startswith(b'MAIL') or command.startswith(b'RSET'):
                self.wfile.write(b'250 OK\r\n')
            elif command.startswith(b'RCPT'):
                recipient = line.decode().strip()
                with lock:
                    reject = state['mode'] == 'reject'
                self.wfile.write(b'550 recipient rejected\r\n' if reject else b'250 OK\r\n')
            elif command.startswith(b'DATA'):
                self.wfile.write(b'354 end with dot\r\n')
                chunks = []
                while True:
                    part = self.rfile.readline(65536)
                    if part in (b'.\r\n', b''):
                        break
                    chunks.append(part)
                with lock:
                    state['data_attempts'] += 1
                    state['messages'].append({'recipient': recipient, 'body': b''.join(chunks).decode('utf-8')})
                    lost = state['mode'] == 'lost_response'
                if lost:
                    return  # Recipient accepted DATA, client cannot observe the acceptance.
                self.wfile.write(b'250 queued\r\n')
            elif command.startswith(b'QUIT'):
                self.wfile.write(b'221 bye\r\n')
                return
            else:
                self.wfile.write(b'500 unsupported\r\n')


class Control(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        with lock:
            if self.path.startswith('/reset/'):
                state.update(mode=self.path.split('/')[-1], messages=[], data_attempts=0)
            body = json.dumps(state).encode()
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


class Server(socketserver.ThreadingTCPServer):
    daemon_threads = True
    allow_reuse_address = True


if __name__ == '__main__':
    smtp = Server(('127.0.0.1', int(sys.argv[1])), SMTP)
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    http.server.ThreadingHTTPServer(('127.0.0.1', int(sys.argv[2])), Control).serve_forever()
