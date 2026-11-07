"""仅向测试运行器启动的私有 FPM socket 发请求，不修改共享 FPM。"""
import json
import socket
import struct


def record(kind, body=b''):
    return struct.pack('!BBHHBB', 1, kind, 1, len(body), 0, 0) + body


def size(length):
    return bytes([length]) if length < 128 else struct.pack('!I', length | 0x80000000)


def request(socket_path, root, method, path, body=None, token=None):
    content = json.dumps(body).encode() if body is not None else b''
    params = {
        'SCRIPT_FILENAME': str(root / 'public/index.php'), 'SCRIPT_NAME': '/index.php',
        'REQUEST_METHOD': method, 'REQUEST_URI': path, 'QUERY_STRING': path.partition('?')[2],
        'SERVER_PROTOCOL': 'HTTP/1.1', 'SERVER_NAME': 'localhost', 'SERVER_PORT': '80',
        'REMOTE_ADDR': '127.0.0.1', 'CONTENT_TYPE': 'application/json', 'CONTENT_LENGTH': str(len(content)),
    }
    if token:
        params['HTTP_AUTHORIZATION'] = 'Bearer ' + token
    encoded = b''.join(size(len(k)) + size(len(v)) + k.encode() + v.encode() for k, v in params.items())
    output = b''
    with socket.socket(socket.AF_UNIX) as connection:
        connection.settimeout(15)
        connection.connect(str(socket_path))
        connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) + record(4) + record(5, content) + record(5))
        def read_exact(length):
            result = b''
            while len(result) < length:
                part = connection.recv(length - len(result))
                if not part:
                    raise RuntimeError('Incomplete FPM response')
                result += part
            return result
        while True:
            _, kind, _, length, padding, _ = struct.unpack('!BBHHBB', read_exact(8))
            payload = read_exact(length)
            if padding:
                read_exact(padding)
            if kind == 6:
                output += payload
            if kind == 3:
                break
    headers, _, raw = output.partition(b'\r\n\r\n')
    status = next((int(line.split()[1]) for line in headers.split(b'\r\n') if line.lower().startswith(b'status:')), 200)
    return status, json.loads(raw)


def verify(socket_path, root):
    assert request(socket_path, root, 'GET', '/health')[0] == 200
    assert request(socket_path, root, 'POST', '/api/v1/auth/login', {
        'email': 'browser@example.test', 'password': 'BrowserFixture!2026',
    })[0] == 200
    status, response = request(socket_path, root, 'GET', '/api/v1/auth/me')
    assert status == 401 and response['data'] is None
    status, response = request(socket_path, root, 'POST', '/api/v1/admin/auth/login', {
        'username': 'browser-admin', 'password': 'BrowserAdmin!2026',
    })
    assert status == 200
    admin_token = response['data']['token']
    assert request(socket_path, root, 'GET', '/api/v1/admin/tasks', token=admin_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/admin/tasks')[0] == 401
    assert request(socket_path, root, 'GET', '/api/v1/auth/me', token=admin_token)[0] == 401
    status, response = request(socket_path, root, 'POST', '/api/v1/auth/login', {
        'email': 'browser@example.test', 'password': 'BrowserFixture!2026',
    })
    assert status == 200
    client_token = response['data']['token']
    assert request(socket_path, root, 'GET', '/api/v1/auth/me', token=client_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/admin/tasks', token=client_token)[0] == 401
    assert request(socket_path, root, 'GET', '/api/v1/admin/auth/me')[0] == 401
    print('PASS: isolated PHP-FPM client/admin authentication, anonymous and cross-scope identity isolation')
