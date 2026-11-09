"""仅向测试运行器启动的私有 FPM socket 发请求，不修改共享 FPM。"""
import json
import socket
import struct
import uuid


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
    assert request(socket_path, root, 'GET', '/api/v1/admin/accounts', token=admin_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/admin/accounts', token=support_token)[0] == 403
    assert request(socket_path, root, 'GET', '/api/v1/admin/accounts', token=client_token)[0] == 401
    assert request(socket_path, root, 'POST', '/api/v1/admin/accounts', {}, token=support_token)[0] == 403
    assert request(socket_path, root, 'GET', '/api/v1/admin/auth/security', token=support_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/admin/auth/security')[0] == 401
    status, tasks = request(socket_path, root, 'GET', f'/api/v1/admin/tasks?user_id={customer_id}', token=support_token)
    assert status == 200 and all(t['total'] is None for t in tasks['data']['items'])
    task_id = tasks['data']['items'][0]['id']
    status, task = request(socket_path, root, 'GET', f'/api/v1/admin/tasks/{task_id}', token=support_token)
    assert status == 200 and not task['data']['can_view_finance'] and task['data']['receipts'] == []
    assert request(socket_path, root, 'GET', '/api/v1/admin/finance/invoices', token=support_token)[0] == 403
    ticket_body = {'title': 'FPM local support request', 'content': '<b>plain text</b>', 'instance_id': instance_id, 'request_key': str(uuid.uuid4())}
    status, created = request(socket_path, root, 'POST', '/api/v1/tickets', ticket_body, token=client_token)
    assert status == 200
    ticket_id = created['data']['ticket_id']
    status, replayed = request(socket_path, root, 'POST', '/api/v1/tickets', ticket_body, token=client_token)
    assert status == 200 and replayed['data']['ticket_id'] == ticket_id and replayed['data']['replayed']
    assert request(socket_path, root, 'GET', '/api/v1/tickets', token=admin_token)[0] == 401
    assert request(socket_path, root, 'GET', '/api/v1/admin/tickets', token=client_token)[0] == 401
    reply = {'content': 'FPM public reply', 'request_key': str(uuid.uuid4())}
    assert request(socket_path, root, 'POST', f'/api/v1/admin/tickets/{ticket_id}/replies', reply, token=support_token)[0] == 200
    status, thread = request(socket_path, root, 'GET', f'/api/v1/tickets/{ticket_id}', token=client_token)
    assert status == 200 and thread['data']['replies']['total'] == 2 and thread['data']['ticket']['status'] == 'answered'
    close_body = {'expected_version': thread['data']['version'], 'request_key': str(uuid.uuid4())}
    assert request(socket_path, root, 'POST', f'/api/v1/tickets/{ticket_id}/close', close_body, token=client_token)[0] == 200
    price_path = f'/api/v1/admin/instances/{instance_id}/renewal-price'
    status, price = request(socket_path, root, 'GET', price_path, token=admin_token)
    assert status == 200 and not price['data']['editable'] and price['data']['blocked_reason']
    assert 'password' not in json.dumps(price) and 'config_snapshot' not in price['data']
    assert request(socket_path, root, 'GET', price_path, token=client_token)[0] == 401
    assert request(socket_path, root, 'GET', price_path, token=support_token)[0] == 403
    assert request(socket_path, root, 'PATCH', price_path, {}, token=support_token)[0] == 403
    status, finance = request(socket_path, root, 'POST', '/api/v1/admin/auth/login', {
        'username': 'browser-finance', 'password': 'BrowserFinance!2026',
    })
    assert status == 200
    finance_token = finance['data']['token']
    payment_settings = '/api/v1/admin/payment-settings/epay'
    status, settings = request(socket_path, root, 'GET', payment_settings, token=admin_token)
    assert status == 200 and settings['data']['identity_locked'] and 'key' not in settings['data'] and 'encrypted_credentials' not in settings['data']
    for other in [support_token, finance_token]:
        assert request(socket_path, root, 'GET', payment_settings, token=other)[0] == 403
        assert request(socket_path, root, 'PATCH', payment_settings, {'enabled': False}, token=other)[0] == 403
    assert request(socket_path, root, 'GET', payment_settings, token=client_token)[0] == 401
    assert request(socket_path, root, 'GET', payment_settings)[0] == 401
    print('PASS: isolated PHP-FPM payment settings current-role and credential isolation')
    # A separate disposable customer keeps the existing client/instance fixture's session intact.
    ca_email = 'fpm-customer-' + str(uuid.uuid4()) + '@example.test'
    status, ca_session = request(socket_path, root, 'POST', '/api/v1/auth/register', {'email': ca_email, 'password': 'FpmCustomer!2026'})
    assert status == 200
    ca_id = ca_session['data']['user']['id']
    ca_token = ca_session['data']['token']
    ca_path = f'/api/v1/admin/customers/{ca_id}'
    status, ca = request(socket_path, root, 'GET', ca_path + '/account', token=admin_token)
    assert status == 200 and set(ca['data']['account']) == {'id', 'email', 'status', 'version'}
    for other in [support_token, finance_token]:
        assert request(socket_path, root, 'GET', ca_path, token=other)[0] == 200
        assert request(socket_path, root, 'GET', ca_path + '/account', token=other)[0] == 403
        assert request(socket_path, root, 'PATCH', ca_path + '/status', {}, token=other)[0] == 403
        assert request(socket_path, root, 'POST', ca_path + '/password', {}, token=other)[0] == 403
    assert request(socket_path, root, 'GET', ca_path + '/account', token=ca_token)[0] == 401
    ca_body = {'expected_version': ca['data']['account']['version'], 'request_key': str(uuid.uuid4()), 'status': 'suspended', 'evidence': 'FPM isolated account acceptance', 'confirmed': True}
    assert request(socket_path, root, 'PATCH', ca_path + '/status', ca_body, token=admin_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/auth/me', token=ca_token)[0] == 403
    status, ca = request(socket_path, root, 'GET', ca_path + '/account?request_key=' + ca_body['request_key'], token=admin_token)
    assert status == 200 and ca['data']['operation']['context']['request_key'] == ca_body['request_key']
    ca_body.update(expected_version=ca['data']['account']['version'], status='active', request_key=str(uuid.uuid4()))
    assert request(socket_path, root, 'PATCH', ca_path + '/status', ca_body, token=admin_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/auth/me', token=ca_token)[0] == 401
    status, ca = request(socket_path, root, 'GET', ca_path + '/account', token=admin_token)
    reset = {'expected_version': ca['data']['account']['version'], 'request_key': str(uuid.uuid4()), 'new_password': 'FpmReplacement!2026', 'evidence': 'FPM explicit password reset', 'confirmed': True}
    assert request(socket_path, root, 'POST', ca_path + '/password', reset, token=admin_token)[0] == 200
    assert request(socket_path, root, 'POST', ca_path + '/password', reset, token=admin_token)[0] == 409
    status, after = request(socket_path, root, 'GET', ca_path + '/account', token=admin_token)
    assert status == 200 and reset['new_password'] not in json.dumps(after)
    print('PASS: isolated PHP-FPM customer account roles, audit lookup, disable, permanent revocation and reset')

    assert request(socket_path, root, 'GET', price_path, token=finance_token)[0] == 200
    assert request(socket_path, root, 'PATCH', price_path, {}, token=finance_token)[0] == 403
    adjustment_path = f'/api/v1/admin/instances/{instance_id}/manual-adjustment'
    status, adjustment = request(socket_path, root, 'GET', adjustment_path, token=admin_token)
    assert status == 200 and not adjustment['data']['editable'] and adjustment['data']['blocked_reason']
    assert request(socket_path, root, 'GET', adjustment_path, token=client_token)[0] == 401
    assert request(socket_path, root, 'GET', adjustment_path, token=support_token)[0] == 403
    assert request(socket_path, root, 'GET', adjustment_path, token=finance_token)[0] == 200
    body = {'new_price': '2.00', 'evidence': 'FPM isolated already completed change'}
    assert request(socket_path, root, 'POST', adjustment_path + '/preview', body, token=finance_token)[0] == 403
    assert request(socket_path, root, 'POST', adjustment_path + '/preview', body, token=admin_token)[0] == 409
    assert request(socket_path, root, 'POST', adjustment_path + '/settle', {}, token=finance_token)[0] == 403
    status, catalog = request(socket_path, root, 'GET', '/api/v1/admin/provider-drivers', token=admin_token)
    assert status == 200 and catalog['data']['items']
    assert request(socket_path, root, 'GET', '/api/v1/admin/provider-connections', token=client_token)[0] == 401
    assert request(socket_path, root, 'GET', '/api/v1/admin/provider-connections', token=support_token)[0] == 200
    assert request(socket_path, root, 'GET', '/api/v1/admin/provider-drivers', token=support_token)[0] == 403
    status, connections = request(socket_path, root, 'GET', '/api/v1/admin/provider-connections?per_page=1', token=admin_token)
    assert status == 200 and len(connections['data']['items']) == 1
    provider_id = connections['data']['items'][0]['id']
    status, connection = request(socket_path, root, 'GET', f'/api/v1/admin/provider-connections/{provider_id}', token=support_token)
    assert status == 200 and 'credentials' not in connection['data'] and 'endpoint' not in connection['data']
    assert request(socket_path, root, 'POST', '/api/v1/admin/provider-connections', {}, token=support_token)[0] == 403
    print('PASS: isolated PHP-FPM provider queries, driver forms and configuration permission isolation')
    print('PASS: isolated PHP-FPM client/admin authentication, anonymous and cross-scope identity isolation')
    print('PASS: isolated PHP-FPM customer/instance navigation and finance role isolation')
    print('PASS: isolated PHP-FPM renewal price read/write permissions and blocked-state projection')
    print('PASS: isolated PHP-FPM manual adjustment financial roles and operation gates')
    print('PASS: isolated PHP-FPM admin account permissions and own security, without cross-request identity leakage')

    print('PASS: isolated PHP-FPM support create/replay/public reply/closure and identity separation')
