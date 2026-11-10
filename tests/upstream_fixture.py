"""Isolated ZJMF protocol/fault fixture. Never used by application runtime."""
import os
import base64
import json
import re
import sys
import threading
import time
import calendar
from urllib.parse import urlencode
from pathlib import Path
import copy
CONFIG_SCHEMA = json.loads((Path(__file__).parent / "fixtures/zjmf-product-14.json").read_text())
ENUM_SCHEMA = json.loads((Path(__file__).parent / "fixtures/zjmf-enum-linkage.json").read_text())
FULL_QUANTITY_SCHEMA = json.loads((Path(__file__).parent / "fixtures/zjmf-product-563.json").read_text())
PRICING_SAMPLE = json.loads((Path(__file__).parent / "fixtures/zjmf-pricing-samples.json").read_text())["samples"][0]
# Synthetic sale protocol: real 563 quantities plus the existing synthetic enum graph.
QUANTITY_SCHEMA = copy.deepcopy(ENUM_SCHEMA)
QUANTITY_SCHEMA["products"]["id"] = 2563
QUANTITY_SCHEMA["config_groups"][0]["options"].extend(copy.deepcopy([o for g in FULL_QUANTITY_SCHEMA["config_groups"] for o in g["options"] if o["option_type"] in [15, 18]]))

def quantity_quote(params, prefix):
    opts = {k[len(prefix):-1]: v[0] for k, v in params.items() if k.startswith(prefix)}
    valid = (len(opts) == 11 and params.get("qty", params.get("cart_data[qty]")) == ["1"] and
        ((opts.get("278142") == "1178980" and opts.get("278141") == "1178975") or
         (opts.get("278142") == "1199990" and opts.get("278141") in ["1178976", "1178977"])) and
        not (opts.get("278141") == "1178976" and opts.get("278143") == "1178981") and
        opts.get("278144") in ["1", "2", "3", "4"] and
        bool(re.fullmatch(r"[0-9]+", opts.get("278145", ""))))
    if not valid:
        return None
    bw = int(opts["278145"])
    if bw < 50 or bw > 1000 or bw % 10:
        return None
    # Deliberately synthetic prices, not local replicas of upstream tier pricing.
    return ("40.00", "35.00") if opts["278144"] != "1" or bw != 50 else ("32.00", "30.00")

from datetime import datetime, timezone
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import parse_qs, urlparse

state = {'mode': 'normal', 'creates': 0, 'hosts': {}, 'host_counter': 700, 'wrong_host_id': False,
         'renew_mode': 'normal', 'renew_calls': 0, 'renew_payments': 0, 'renew_invoices': {}, 'config_mode': 'normal', 'last_create_total': '0.01', 'power_mode': 'normal', 'power_calls': 0, 'reinstall_mode': 'normal', 'reinstall_calls': 0, 'request_counts': {}, 'service_mode': 'normal'}
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
        if not path.startswith('/__'):
            with lock:
                state['request_counts'][path] = state['request_counts'].get(path, 0) + 1
        if path == '/__mode':
            with lock:
                state.update(mode=p['mode'][0], creates=0, hosts={}, wrong_host_id=False,
                             renew_mode='normal', renew_calls=0, renew_payments=0, renew_invoices={})
            r = {'ok': True}
        elif path == '/__renew_mode':
            state['renew_mode'] = p['mode'][0]
            r = {'ok': True}
        elif path == '/__suspend':
            state['hosts'][int(p['hostid'][0])].update(domainstatus='Suspended', suspendreason_type=p.get('reason', ['due'])[0])
            r = {'ok': True}
        elif path == '/__service':
            with lock:
                h = state['hosts'][int(p['hostid'][0])]
                for query, field in [('status', 'domainstatus'), ('reason', 'suspendreason_type'), ('power', 'power_state')]:
                    if query in p: h[field] = p[query][0]
                if 'due' in p: h['nextduedate'] = int(p['due'][0])
            r = {'ok': True}
        elif path == '/__service_mode':
            state['service_mode'] = p['mode'][0]
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
        elif path == '/__config_mode':
            state['config_mode'] = p['mode'][0]
            state['refresh_reads'] = 0
            r = {'ok': True}
        elif path == '/__reinstall_mode':
            state['reinstall_mode'] = p['mode'][0]
            r = {'ok': True}
        elif path == '/__reinstall_phase':
            h = state['hosts'][int(p['hostid'][0])]
            h['reinstall_phase'] = p['phase'][0]
            r = {'ok': True}
        elif path == '/__power_mode':
            state['power_mode'] = p['mode'][0]
            r = {'ok': True}
        elif path == '/__power_state':
            state['hosts'][int(p['hostid'][0])]['power_state'] = p['state'][0]
            r = {'ok': True}
        elif path == '/__state':
            r = {'creates': state['creates'], 'hosts': len(state['hosts']), 'renew_calls': state['renew_calls'], 'renew_payments': state['renew_payments'], 'last_config': state.get('last_config', {}), 'last_qty': state.get('last_qty'), 'power_calls': state['power_calls'], 'reinstall_calls': state['reinstall_calls'], 'last_reinstall': state.get('last_reinstall', {})}
        elif path == '/__request_counts':
            r = dict(state['request_counts'])
        elif path == '/zjmf_api_login':
            r = {'status': 200, 'jwt': 'isolated-test-token'}
        elif self.headers.get('Authorization') != 'Bearer isolated-test-token':
            r = {'status': 405}
        elif path == '/cart/get_product_config':
            data = {'products': {'id': 391}, 'config_groups': [], 'customfields': [], 'advanced': []}
            if p.get('pid') == ['389']:
                if state['config_mode'] == 'refresh_unavailable':
                    self.send_error(503, 'Isolated supplier unavailable')
                    return
                data = copy.deepcopy(PRICING_SAMPLE['schema'])
                with lock:
                    state['refresh_reads'] = state.get('refresh_reads', 0) + 1
                if state['config_mode'] == 'refresh_drift' and state['refresh_reads'] >= 3:
                    data['product_pricings'][0]['monthly'] = '2.00'
                if state['config_mode'] == 'refresh_delay':
                    time.sleep(2)
                if state['config_mode'] == 'refresh_bad':
                    del data['config_groups'][0]['options'][0]['sub'][0]['pricings']
                if state['config_mode'] == 'refresh_rule_price':
                    data['product_pricings'][0]['monthly'] = '2.00'
                if state['config_mode'] == 'refresh_unused_price':
                    data['config_groups'][0]['options'][1]['sub'][1]['pricings'][0]['monthly'] = '1.00'
                if state['config_mode'] == 'refresh_stock':
                    data['products'].update(stock_control=1, qty=9, upstream_qty=9)
                if state['config_mode'] == 'preview_generation':
                    data['products']['host'] = {'show': 1, 'prefix': 'changed', 'rule': {'num': 1, 'len_num': 12}}
            if p.get('pid') == ['14']:
                data = copy.deepcopy(CONFIG_SCHEMA)
                if state['config_mode'] == 'schema':
                    data['config_groups'][0]['options'][0]['sub'][0]['option_name'] += ' changed'
                if state['config_mode'] == 'input_rules':
                    data['products']['password']['rule']['len_num'] = 16
            if p.get('pid') == ['1563']:
                data = copy.deepcopy(ENUM_SCHEMA)
                if state['config_mode'] == 'enum_rules':
                    data['advanced'][1]['sub_id']['1178976'] = {'qty_minimum': '0', 'qty_maximum': '0'}
            if p.get('pid') == ['563']:
                data = copy.deepcopy(FULL_QUANTITY_SCHEMA)
            if p.get('pid') == ['2563']:
                data = copy.deepcopy(QUANTITY_SCHEMA)
                if state['config_mode'] == 'quantity_step':
                    data['config_groups'][0]['options'][-1]['qty_stage'] = 20
                if state['config_mode'] == 'quantity_ranges':
                    data['config_groups'][0]['options'][-1]['sub'][0]['qty_maximum'] = 60
                    data['config_groups'][0]['options'][-1]['sub'][1]['qty_minimum'] = 61
            r = {'status': 200, 'data': data}
        elif path == '/cart/get_total':
            total, recurring = '0.01', '0.01'
            if p.get('pid') == ['389']:
                opts = {k[13:-1]: v[0] for k, v in p.items() if k.startswith('configoption[')}
                case = next((c for c in PRICING_SAMPLE['cases'] if c['config_options'] == opts), None)
                if case is None or p.get('qty') != ['1']:
                    self.send_error(422, 'Unrecorded pricing comparison configuration')
                    return
                # Independent fixed supplier capture, not a second implementation of the calculator.
                total, recurring = case['first'], case['recurring']
                if state['config_mode'] == 'refresh_rule_price':
                    from decimal import Decimal
                    total = recurring = str(Decimal(total) + Decimal('1.00'))
                if state['config_mode'] == 'refresh_delay':
                    time.sleep(2)
                if state['config_mode'] == 'pricing_mismatch':
                    recurring = '999.99'
            if p.get('pid') == ['14']:
                opts = {k[13:-1]: v[0] for k, v in p.items() if k.startswith('configoption[')}
                fields = [o for g in CONFIG_SCHEMA['config_groups'] for o in g['options']]
                valid = len(opts) == len(fields) and all(opts.get(str(o['id'])) in [str(v['id']) for v in o['sub']] for o in fields)
                if not valid or state['config_mode'] == 'unavailable':
                    self.send_error(422, 'Invalid configuration or unavailable quote')
                    return
                total, recurring = ('16.00', '14.00') if opts['276853'] == '1173030' else ('24.00', '22.00')
                if state['config_mode'] == 'price': total = '17.00'
                if state['config_mode'] == 'recurring': recurring = '15.00'
            if p.get('pid') == ['1563']:
                opts = {k[13:-1]: v[0] for k, v in p.items() if k.startswith('configoption[')}
                # Independent fixture oracle: never accepts a forbidden combination by silently fixing it.
                valid = (len(opts) == 9 and
                    ((opts.get('278142') == '1178980' and opts.get('278141') == '1178975') or
                     (opts.get('278142') == '1199990' and opts.get('278141') in ['1178976', '1178977'])) and
                    not (opts.get('278141') == '1178976' and opts.get('278143') == '1178981'))
                if not valid:
                    self.send_error(422, 'Invalid linked configuration')
                    return
                total, recurring = ('16.00', '14.00') if opts['278142'] == '1178980' else ('24.00', '22.00')
            if p.get('pid') == ['2563']:
                prices = quantity_quote(p, 'configoption[')
                if prices is None:
                    self.send_error(422, 'Invalid integer quantity or bulk qty')
                    return
                total, recurring = prices
            r = {'status': 200, 'currency': {'code': 'CNY'}, 'products': {'billingcycle': p['billingcycle'][0], 'stock_control': 1 if p.get('pid') == ['563'] else 0, 'qty': 0 if p.get('pid') == ['563'] else 81, 'total': total, 'signal_price': recurring}}
            if state['config_mode'] in ['stock_one', 'stock_two', 'stock_empty', 'stock_bad']:
                r['products'].update(stock_control=1, qty={'stock_one': 1, 'stock_two': 2, 'stock_empty': 0, 'stock_bad': 'invalid'}[state['config_mode']])
            if state['config_mode'] == 'stock_unlimited_zero':
                r['products'].update(stock_control=0, qty=0)
        elif path == '/cart/settle':
            quantity_prices = quantity_quote(p, 'cart_data[configoptions][') if p.get('cart_data[pid]') == ['2563'] else None
            if p.get('cart_data[pid]') == ['2563'] and quantity_prices is None:
                self.send_error(422, 'Invalid delivery quantity or bulk qty')
                return
            hostname = p['cart_data[host]'][0]
            expected_name = r'ser[0-9]{12}' if p['cart_data[pid]'] == ['14'] else r'[0-9A-Za-z]{8}'
            if not re.fullmatch(expected_name, hostname):
                self.send_error(422, 'Invalid upstream hostname')
                return
            if p['cart_data[pid]'] == ['14']:
                password = p['cart_data[password]'][0]
                if not re.fullmatch(r'[A-Za-z0-9]{12,20}', password) or not all(re.search(x, password) for x in [r'[a-z]',r'[A-Z]',r'[0-9]']):
                    self.send_error(422, 'Invalid upstream password')
                    return
            with lock:
                state['last_config'] = {k[25:-1]: v[0] for k, v in p.items() if k.startswith('cart_data[configoptions][')}
                total, recurring = ('0.01', '0.01')
                if p['cart_data[pid]'] == ['14']:
                    total, recurring = ('16.00', '14.00') if state['last_config'].get('276853') == '1173030' else ('24.00', '22.00')
                if p['cart_data[pid]'] == ['1563']:
                    total, recurring = ('16.00', '14.00') if state['last_config'].get('278142') == '1178980' else ('24.00', '22.00')
                if quantity_prices:
                    total, recurring = quantity_prices
                state['last_qty'] = p.get('cart_data[qty]')
                state['last_create_total'] = total
                state['creates'] += 1
                state['host_counter'] += 1
                h = {'id': state['host_counter'], 'domain': p['cart_data[host]'][0], 'productid': int(p['cart_data[pid]'][0]), 'billingcycle': p['cart_data[billingcycle]'][0], 'firstpaymentamount': total, 'amount': recurring, 'nextduedate': int(time.time()) + 30*86400, 'domainstatus': 'Pending' if state['mode'] == 'pending' else 'Active', 'username': 'cdn-test-user', 'password': 'RemoteSecret!NotInList', 'dedicatedip': ''}
                state['hosts'][h['id']] = h
                mode = state['mode']
            if mode == 'drop_after_create':
                self.close_connection = True
                return
            if mode == 'hold_after_create':
                time.sleep(10)
            r = {'status': 200, 'data': {'invoiceid': 900}}
        elif path == '/invoice_page':
            invoice = int(p['invoiceid'][0])
            amount = state['hosts'][state['renew_invoices'][invoice]]['amount'] if invoice in state['renew_invoices'] else state['last_create_total']
            r = {'status': 200, 'data': {'invoice_total': amount, 'invoice_subtotal': amount}}
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
        elif path == '/host/header':
            h = dict(state['hosts'][int(p['host_id'][0])])
            if state['service_mode'] == 'wrong_header': h['id'] += 1
            if state['service_mode'] == 'header_due': h['nextduedate'] += 1
            if state['service_mode'] == 'header_product': h['productid'] += 1
            if state['service_mode'] == 'header_cycle': h['billingcycle'] = 'invalid'
            if state['service_mode'] == 'header_state': h['domainstatus'] = 'Active'
            if state['service_mode'] == 'header_type': h['suspendreason_type'] = ['due']
            if state['power_mode'] == 'wrong_header' or state['reinstall_mode'] == 'wrong_header': h['id'] += 1
            if state['reinstall_mode'] == 'missing_credentials': h['password'] = ''
            h['type'] = 'dcimcloud' if state['reinstall_mode'] != 'unsupported' else 'dcim'
            if state['reinstall_mode'] == 'unavailable' or state['service_mode'] == 'header_unavailable':
                self.send_error(503)
                return
            r = {'status': 200, 'data': {'host_data': h, 'module_power_status': state['power_mode'] != 'unsupported',
                 'module_button': {'console': [{'type': 'default', 'func': 'vnc'}], 'control': [{'type': 'default', 'func': f} for f in ['on', 'off', 'reboot', 'hard_off', 'reinstall']]},
                 'dcim': {'auth': {'off': 'off'}} if state['power_mode'] == 'deny_stop' else {},
                 'cloud_os': [{'id': 1173023, 'name': 'Debian-12.0_x64'}, {'id': 1173028, 'name': 'Ubuntu-24.04-x64' + (' changed' if state['reinstall_mode'] == 'schema' else '')}],
                 'supplier_secret': 'NEVER-EXPOSE-HEADER-SECRET'}}
        elif path == '/provision/default':
            h = state['hosts'][int(p['id'][0])]
            func = p['func'][0]
            if func == 'vnc':
                target = os.getenv('CONSOLE_TEST_WS_URL', 'ws://127.0.0.1:1/vnc')
                query = urlencode({'url': base64.b64encode(target.encode()).decode(), 'password': 'password', 'host_token': 'SECRET-HOST-TOKEN'})
                page = 'http://' + self.headers['Host'] + '/dcim/novnc?' + query
                r = {'status': 200, 'data': {'url': page, 'pass': 'password', 'zjmfcloud_out_vnc': False}}
            elif func == 'reinstall':
                if p.get('is_api') != ['1'] or p.get('format_data_disk') != ['0'] or p.get('os') not in [['1173023'], ['1173028']] or 'password' in p:
                    self.send_error(422)
                    return
                with lock:
                    state['reinstall_calls'] += 1
                    state['last_reinstall'] = p
                    # These change immediately at acceptance, not on completion: deliberately misleading metadata.
                    h['os'] = 'Debian-12.0_x64'
                    h['username'] = 'root'
                    h['password'] = 'NEVER-EXPOSE-NEW-HEADER-PASSWORD'
                    h['reinstall_phase'] = 'idle' if state['reinstall_mode'] == 'missed_execution' else 'executing'
                if state['reinstall_mode'] == 'drop':
                    self.close_connection = True
                    return
                r = {'status': 406 if state['reinstall_mode'] == 'business_error' else 200, 'msg': 'NEVER-EXPOSE-REINSTALL-SECRET'}
            elif func == 'status':
                if state['power_mode'] == 'unavailable':
                    self.send_error(503)
                    return
                phase = h.get('reinstall_phase', 'idle')
                status, description = h.get('power_state', 'on'), 'NEVER-EXPOSE-STATUS-SECRET'
                if phase == 'executing': status, description = 'process', '任务处理中'
                elif phase == 'unrelated': status, description = 'process', '创建快照中'
                elif phase == 'generic': status, description = 'process', '任务处理中'
                elif phase in ['localized_only', 'wrong_task_type', 'invalid_task_type', 'numeric_task_type', 'id_only']:
                    status, description = 'process', '重装系统中'
                elif phase == 'unknown': status = 'unknown'
                r = {'status': 200, 'data': {'status': status, 'des': description}}
                if phase == 'executing': r['data']['task_type'] = 'reinstall'
                elif phase == 'wrong_task_type': r['data']['task_type'] = 'snapshot_create'
                elif phase == 'invalid_task_type': r['data']['task_type'] = ['reinstall']
                elif phase == 'numeric_task_type': r['data']['task_type'] = 3
                elif phase == 'id_only': r['data']['operation_id'] = 'untyped-operation'
                elif phase == 'typed_idle': r['data']['task_type'] = 'reinstall'
            else:
                if func not in ['on', 'off', 'reboot'] or p.get('is_api') != ['1']:
                    self.send_error(422)
                    return
                with lock:
                    state['power_calls'] += 1
                    h['power_state'] = 'off' if func == 'off' else 'on'
                if state['power_mode'] == 'drop':
                    self.close_connection = True
                    return
                if state['power_mode'] == 'hold': time.sleep(10)
                r = {'status': 406 if state['power_mode'] == 'business_error' else 200, 'msg': 'NEVER-EXPOSE-OPERATION-SECRET'}
        elif path == '/cart/hostinfo':
            if state['service_mode'] == 'unavailable':
                self.send_error(503, 'NEVER-EXPOSE-SERVICE-SECRET')
                return
            hosts = [dict(h) for h in state['hosts'].values() if str(h['id']) in p.get('hostid[0]', [])]
            if state['service_mode'] == 'delay_read': time.sleep(2)
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
