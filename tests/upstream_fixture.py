"""Isolated ZJMF protocol/fault fixture. Never used by application runtime."""
import json
import re
import sys
import threading
import time
import calendar
from datetime import datetime, timezone
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import parse_qs, urlparse

state = {'mode': 'normal', 'creates': 0, 'hosts': {}, 'host_counter': 700, 'wrong_host_id': False,
         'renew_mode': 'normal', 'renew_calls': 0, 'renew_payments': 0, 'renew_invoices': {}}
lock = threading.Lock()

def next_due(stamp, cycle):
    d = datetime.fromtimestamp(stamp, timezone.utc)
    year, month = (d.year + 1, d.month) if cycle == 'annually' else (d.year + d.month // 12, d.month % 12 + 1)
    return int(d.replace(year=year, month=month, day=min(d.day, calendar.monthrange(year, month)[1])).timestamp())

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        self.handle_api('GET')

    def do_POST(self):
        self.handle_api('POST')

    def handle_api(self, method):
        u = urlparse(self.path)
        p = parse_qs(u.query if method == 'GET' else self.rfile.read(int(self.headers.get('Content-Length', 0))).decode())
        path = u.path
        if path == '/__mode':
            with lock:
                state.update(mode=p['mode'][0], creates=0, hosts={}, wrong_host_id=False,
                             renew_mode='normal', renew_calls=0, renew_payments=0, renew_invoices={})
            r = {'ok': True}
        elif path == '/__renew_mode':
            state['renew_mode'] = p['mode'][0]
            r = {'ok': True}
        elif path == '/__suspend':
            state['hosts'][int(p['hostid'][0])]['domainstatus'] = 'Suspended'
            r = {'ok': True}
        elif path == '/__cycle':
            state['hosts'][int(p['hostid'][0])]['billingcycle'] = p['cycle'][0]
            r = {'ok': True}
        elif path == '/__rename_activate':
            with lock:
                for h in state['hosts'].values():
                    h.update(domain='Renamed8', domainstatus='Active')
            r = {'ok': True}
        elif path == '/__wrong_host_id':
            state['wrong_host_id'] = p['enabled'][0] == '1'
            r = {'ok': True}
        elif path == '/__state':
            r = {'creates': state['creates'], 'hosts': len(state['hosts']), 'renew_calls': state['renew_calls'], 'renew_payments': state['renew_payments']}
        elif path == '/zjmf_api_login':
            r = {'status': 200, 'jwt': 'isolated-test-token'}
        elif self.headers.get('Authorization') != 'Bearer isolated-test-token':
            r = {'status': 405}
        elif path == '/cart/get_product_config':
            r = {'status': 200, 'data': {'products': {'id': 391}, 'config_groups': [], 'customfields': [], 'advanced': []}}
        elif path == '/cart/get_total':
            r = {'status': 200, 'currency': {'code': 'CNY'}, 'products': {'billingcycle': p['billingcycle'][0], 'stock_control': 0, 'qty': 81, 'total': '0.01'}}
        elif path == '/cart/settle':
            hostname = p['cart_data[host]'][0]
            if not re.fullmatch(r'[0-9A-Za-z]{8}', hostname) or not all(re.search(x, hostname) for x in [r'[a-z]',r'[A-Z]',r'[0-9]']):
                self.send_error(422, 'Invalid upstream hostname')
                return
            with lock:
                state['creates'] += 1
                state['host_counter'] += 1
                h = {'id': state['host_counter'], 'domain': p['cart_data[host]'][0], 'productid': int(p['cart_data[pid]'][0]), 'billingcycle': p['cart_data[billingcycle]'][0], 'firstpaymentamount': '0.01', 'amount': '0.01', 'nextduedate': int(time.time()) + 30*86400, 'domainstatus': 'Pending' if state['mode'] == 'pending' else 'Active', 'username': 'cdn-test-user', 'password': 'RemoteSecret!NotInList', 'dedicatedip': ''}
                state['hosts'][h['id']] = h
                mode = state['mode']
            if mode == 'drop_after_create':
                self.close_connection = True
                return
            if mode == 'hold_after_create':
                time.sleep(10)
            r = {'status': 200, 'data': {'invoiceid': 900}}
        elif path == '/invoice_page':
            r = {'status': 200, 'data': {'invoice_total': '0.01', 'invoice_subtotal': '0.01'}}
        elif path == '/host/renewpage':
            h = state['hosts'][int(p['hostid'][0])]
            r = {'status': 200, 'data': {'host': h, 'currency': {'code': 'CNY'}, 'cycle': [
                {'billingcycle': h['billingcycle'], 'duration': next_due(h['nextduedate'], h['billingcycle']) - h['nextduedate']} ]}}
        elif path == '/host/renew':
            hid = int(p['hostid'][0])
            with lock:
                state['renew_calls'] += 1
                invoice = 2000 + state['renew_calls']
                state['renew_invoices'][invoice] = hid
            if state['renew_mode'] == 'drop_submit':
                self.close_connection = True
                return
            r = {'status': 200, 'data': {'invoiceid': invoice}}
        elif path == '/apply_credit':
            invoice = int(p['invoiceid'][0])
            if invoice in state['renew_invoices']:
                with lock:
                    state['renew_payments'] += 1
                    h = state['hosts'][state['renew_invoices'][invoice]]
                    h['nextduedate'] = next_due(h['nextduedate'], h['billingcycle'])
                    if state['renew_mode'] != 'hold_restore':
                        h['domainstatus'] = 'Active'
                if state['renew_mode'] == 'drop_payment':
                    self.close_connection = True
                    return
                if state['renew_mode'] == 'hold_payment':
                    time.sleep(10)
                r = {'status': 1001, 'data': {'hostid': [h['id']]}}
            else:
                r = {'status': 1001, 'data': {'hostid': list(state['hosts'])[-1:]}}
        elif path == '/host/list':
            rows = [dict(h) for h in state['hosts'].values() if p.get('search', [''])[0] == h['domain']]
            for row in rows:
                row.pop('username'); row.pop('password')
            r = {'status': 200, 'data': {'sum': len(rows), 'list': rows}}
        elif path == '/cart/hostinfo':
            hosts = [dict(h) for h in state['hosts'].values() if str(h['id']) in p.get('hostid[0]', [])]
            if state['wrong_host_id']:
                for h in hosts:
                    h['id'] += 1000
            r = {'status': 200, 'data': {'hosts': hosts}}
        else:
            r = {'status': 404}
        data = json.dumps(r).encode()
        try:
            self.send_response(200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            pass

ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), Handler).serve_forever()
