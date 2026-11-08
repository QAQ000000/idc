"""Real HTTP + WS + RFB boundary tests using only Python stdlib, against disposable servers."""
import base64
import hashlib
import json
import os
from pathlib import Path
import socket
import struct
import time
import urllib.request

class Client:
    def __init__(self, address, path, protocols, origin='https://console.example.test'):
        self.sock=socket.create_connection(address,timeout=5)
        self.sock.settimeout(5)
        key=base64.b64encode(os.urandom(16)).decode()
        req=f'GET {path} HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {key}\r\nOrigin: {origin}\r\nSec-WebSocket-Protocol: {protocols}\r\n\r\n'
        self.sock.sendall(req.encode());self.buf=b''
        while b'\r\n\r\n' not in self.buf:self.buf+=self.sock.recv(4096)
        headers,self.buf=self.buf.split(b'\r\n\r\n',1)
        self.status=int(headers.split(b' ')[1])
        if self.status==101:
            expected=base64.b64encode(hashlib.sha1((key+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest())
            assert expected in headers and b'Sec-WebSocket-Protocol: binary' in headers
            assert b'jianyun.' not in headers
    def exact(self,n):
        while len(self.buf)<n:
            chunk=self.sock.recv(65536)
            if not chunk:raise EOFError()
            self.buf+=chunk
        out,self.buf=self.buf[:n],self.buf[n:];return out
    def recv(self):
        a,b=self.exact(2);n=b&127
        if n==126:n=struct.unpack('!H',self.exact(2))[0]
        if n==127:n=struct.unpack('!Q',self.exact(8))[0]
        assert not b&128 and n<=4194304
        data=self.exact(n)
        if a&15==8:raise EOFError()
        assert a&15==2
        return data
    def send(self,data):
        mask=os.urandom(4);n=len(data)
        head=bytes([130,n|128]) if n<126 else bytes([130,254])+struct.pack('!H',n)
        self.sock.sendall(head+mask+bytes(v^mask[i%4] for i,v in enumerate(data)))
    def close(self):self.sock.close()

def verify_console(env):
    address=('127.0.0.1',int(env['CONSOLE_LISTEN'].rsplit(':',1)[1]))
    deadline=time.monotonic()+10
    while True:
        try:s=socket.create_connection(address,timeout=1);s.close();break
        except OSError:
            if time.monotonic()>deadline:raise
            time.sleep(.1)
    auth=json.loads(Path(env['CONSOLE_TEST_AUTH_FILE']).read_text())
    def issue(token=None):
        req=urllib.request.Request(env['TEST_BASE_URL']+f"/api/v1/instances/{auth['instance']}/console",data=b'{}',headers={'Content-Type':'application/json','Authorization':'Bearer '+(token or auth['token'])})
        with urllib.request.urlopen(req,timeout=10) as r:
            assert 'no-store' in r.headers['Cache-Control']
            return json.load(r)['data']['path']
    token=auth['token'];checks=0
    def check(ok,label):
        nonlocal checks
        assert ok,label;checks+=1;print('PASS console relay: '+label,flush=True)
    path=issue()
    c=Client(address,path,'binary');check(c.status==403,'ticket alone does not authenticate a console');c.close()
    c=Client(address,path,'binary, jianyun.'+token,origin='https://evil.example');check(c.status==403,'wrong Origin denied before target access');c.close()
    c=Client(address,path,'binary, jianyun.'+auth['other']);check(c.status==403,'different owner denied');c.close()
    # A valid mismatched session consumes the ticket, so obtain a fresh authorization.
    time.sleep(5.1);path=issue()
    c=Client(address,path,'binary, jianyun.'+token);check(c.status==101,'main session plus fresh ticket upgrades only binary protocol')
    check(c.recv()==b'RFB 003.008\n','upstream VNC authentication completed on server')
    c.send(b'RFB 003.008\n');check(c.recv()==b'\x01\x01','browser receives no supplier password challenge')
    c.send(b'\x01');check(c.recv()==bytes(4),'browser security result succeeds after independent authorization')
    c.send(b'\x01');init=c.recv();check(init[24:]==b'JianYun Console','supplier desktop name sanitized')
    c.send(b'client-test');check(c.recv()==b'client-test','authenticated relay carries bidirectional binary data')
    d=Client(address,path,'binary, jianyun.'+token);check(d.status==403,'live ticket replay refused');d.close();c.close()
    time.sleep(5.1)
    # Token expiry during a live connection is enforced, independent of the ticket TTL.
    # Mint just before use, rather than relying on earlier suite runtime.
    import subprocess
    code="require 'bootstrap.php';echo App\\Core\\Security::generateToken(['uid'=>"+str(json.loads(base64.urlsafe_b64decode(token.split('.')[1]+'=='))['uid'])+"],3);"
    short=subprocess.check_output(['php','-r',code],cwd=Path(__file__).resolve().parents[1],env=env,text=True)
    path=issue(short);c=Client(address,path,'binary, jianyun.'+short);assert c.status==101;c.recv()
    time.sleep(3.2)
    closed=False
    try:c.recv()
    except (EOFError,ConnectionResetError):closed=True
    check(closed,'main login expiry closes existing console connection');c.close()
    print(f'Console relay checks: {checks}',flush=True)
