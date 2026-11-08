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
    for path in ['/admin/customers', '/admin/instances']:
        assert request(socket_path, root, 'GET', '/api/v1' + path, token=admin_token)[0] == 200
        assert request(socket_path, root, 'GET', '/api/v1' + path, token=client_token)[0] == 401
        assert request(socket_path, root, 'GET', '/api/v1' + path)[0] == 401
    status, customer = request(socket_path, root, 'GET', '/api/v1/admin/customers?email=browser%40example.test', token=admin_token)
    assert status == 200 and customer['data']['total'] == 1
    customer_id = customer['data']['items'][0]['id']
    status, customer = request(socket_path, root, 'GET', f'/api/v1/admin/customers/{customer_id}', token=admin_token)
    assert status == 200 and customer['data']['instances']['total'] == 2
    instance_id = customer['data']['instances']['items'][0]['id']
    assert request(socket_path, root, 'GET', f'/api/v1/admin/instances/{instance_id}', token=admin_token)[0] == 200
    status, support = request(socket_path, root, 'POST', '/api/v1/admin/auth/login', {
        'username': 'browser-support', 'password': 'BrowserSupport!2026',
    })
    assert status == 200
    support_token = support['data']['token']
    status, tasks = request(socket_path, root, 'GET', f'/api/v1/admin/tasks?user_id={customer_id}', token=support_token)
    assert status == 200 and all(t['total'] is None for t in tasks['data']['items'])
    task_id = tasks['data']['items'][0]['id']
    status, task = request(socket_path, root, 'GET', f'/api/v1/admin/tasks/{task_id}', token=support_token)
    assert status == 200 and not task['data']['can_view_finance'] and task['data']['receipts'] == []
    assert request(socket_path, root, 'GET', '/api/v1/admin/finance/invoices', token=support_token)[0] == 403
    print('PASS: isolated PHP-FPM client/admin authentication, anonymous and cross-scope identity isolation')
    print('PASS: isolated PHP-FPM customer/instance navigation and finance role isolation')
