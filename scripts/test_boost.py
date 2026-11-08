#!/usr/bin/env python3
"""Exercise Boost through its real stdio protocol; no business environment is used."""
import argparse
import json
import os
from pathlib import Path
import selectors
import shutil
import signal
import subprocess
import sys
import tempfile
import time


ROOT = Path(__file__).resolve().parents[1]
WRAPPER = ROOT / 'scripts' / 'boost_mcp.py'
APPLICATION_INFO = 'file://instructions/application-info.md'
BASE_TOOLS = {'get-absolute-url', 'search-docs'}
DATABASE_TOOLS = {'database-connections', 'database-query', 'database-schema'}
DATABASE_KEYS = ('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')
SENTINEL = 'jianyun-boost-business-sentinel'


class Checks:
    def __init__(self):
        self.count = 0

    def require(self, condition, message):
        if not condition:
            raise RuntimeError('Boost check failed: ' + message)
        self.count += 1


class MCPClient:
    """Small synchronous JSON-RPC client with bounded reads and process cleanup."""
    def __init__(self, php, env=None, database_config=None):
        command = [sys.executable, str(WRAPPER), '--php', str(php)]
        if database_config is not None:
            command += ['--database-config', str(database_config)]
        self.process = subprocess.Popen(command, cwd=ROOT, env=env, stdin=subprocess.PIPE,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                        start_new_session=True, bufsize=0)
        self.selector = selectors.DefaultSelector()
        self.selector.register(self.process.stdout, selectors.EVENT_READ, 'stdout')
        self.selector.register(self.process.stderr, selectors.EVENT_READ, 'stderr')
        self.buffer = b''
        self.stderr = b''
        self.request_id = 0

    def request(self, method, params=None, timeout=20):
        self.request_id += 1
        request_id = self.request_id
        request = {'jsonrpc': '2.0', 'id': request_id, 'method': method}
        if params is not None:
            request['params'] = params
        self._send(request)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            while b'\n' in self.buffer:
                line, self.buffer = self.buffer.split(b'\n', 1)
                if not line.strip():
                    continue
                try:
                    response = json.loads(line)
                except (ValueError, UnicodeError):
                    raise RuntimeError('Boost emitted invalid protocol output') from None
                if response.get('id') == request_id:
                    return response
            events = self.selector.select(min(0.5, max(0, deadline - time.monotonic())))
            for key, _ in events:
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    self.selector.unregister(key.fileobj)
                    continue
                if key.data == 'stdout':
                    self.buffer += chunk
                else:
                    self.stderr += chunk
            if not self.selector.get_map() and b'\n' not in self.buffer:
                raise RuntimeError('Boost closed before completing the protocol request')
        raise RuntimeError('Boost protocol request timed out: ' + method)

    def _send(self, message):
        try:
            self.process.stdin.write((json.dumps(message) + '\n').encode())
            self.process.stdin.flush()
        except (BrokenPipeError, OSError):
            raise RuntimeError('Boost protocol input closed unexpectedly') from None

    def initialize(self):
        result = self.request('initialize', {
            'protocolVersion': '2025-06-18', 'capabilities': {},
            'clientInfo': {'name': 'jianyun-boost-checks', 'version': '1.0'},
        })
        self._send({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
        return result

    def tool(self, name, arguments=None):
        return self.request('tools/call', {'name': name, 'arguments': arguments or {}})

    def close(self):
        if not self.process.stdin.closed:
            self.process.stdin.close()
        try:
            returncode = self.process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            self.kill()
            raise RuntimeError('Boost did not exit after closing protocol input') from None
        self.selector.close()
        for stream in (self.process.stdout, self.process.stderr):
            stream.close()
        return returncode

    def kill(self):
        try:
            self.process.terminate()
            # The launcher owns a separate PHP process group and needs its cleanup grace period.
            self.process.wait(timeout=7)
        except subprocess.TimeoutExpired:
            os.killpg(self.process.pid, signal.SIGKILL)
            self.process.wait(timeout=5)
        except ProcessLookupError:
            self.process.wait(timeout=5)
        self.selector.close()
        for stream in (self.process.stdin, self.process.stdout, self.process.stderr):
            if not stream.closed:
                stream.close()


def successful(response):
    return 'result' in response and not response['result'].get('isError', False)


def response_text(response):
    content = response.get('result', {}).get('content', [])
    return '\n'.join(item.get('text', '') for item in content if item.get('type') == 'text')


def tool_json(response):
    if not successful(response):
        raise RuntimeError('Boost tool did not complete successfully')
    try:
        return json.loads(response_text(response))
    except ValueError:
        raise RuntimeError('Boost tool did not return valid JSON data') from None


def polluted_environment(directory):
    """Inherited business settings must never reach the isolated Boost process."""
    directory = Path(directory)
    trap = directory / 'inherited-environment'
    trap.mkdir(exist_ok=True)
    (trap / '.env').write_text('APP_URL=https://' + SENTINEL + '.invalid\nDB_DATABASE=' + SENTINEL + '\n')
    marker = directory / 'cached-business-config-was-loaded'
    cache = directory / 'inherited-config.php'
    cache.write_text('<?php file_put_contents(' + json.dumps(str(marker)) + ', "loaded"); return [];\n')
    env = os.environ.copy()
    env.pop('JIANYUN_BOOST_DATABASE_CONFIG', None)
    env.update(APP_ENV='production', APP_ENVIRONMENT_PATH=str(trap), APP_CONFIG_CACHE=str(cache),
               APP_URL='https://' + SENTINEL + '.invalid', APP_KEY=SENTINEL,
               DB_HOST=SENTINEL + '.invalid', DB_PORT='3306', DB_DATABASE=SENTINEL,
               DB_USERNAME=SENTINEL, DB_PASSWORD=SENTINEL,
               JIANYUN_BOOST_DATABASE='1', JIANYUN_BOOST_ENABLED='0')
    return env, marker


def verify_default(php, directory, checks):
    env, marker = polluted_environment(directory)
    client = MCPClient(php, env=env)
    try:
        initialized = client.initialize()
        checks.require(successful(initialized) and 'serverInfo' in initialized['result'], 'MCP initialize')
        listed = client.request('tools/list')
        names = {tool['name'] for tool in listed.get('result', {}).get('tools', [])}
        checks.require(names == BASE_TOOLS, 'default tool allowlist excludes database and execution tools')
        resources = client.request('resources/list')
        checks.require(APPLICATION_INFO in {item['uri'] for item in resources.get('result', {}).get('resources', [])},
                       'application information resource is discoverable')
        application = client.request('resources/read', {'uri': APPLICATION_INFO})
        texts = application.get('result', {}).get('contents', [])
        checks.require(successful(application) and bool(texts), 'application information can be read without a database')
        info = json.loads(texts[0]['text'])
        checks.require(str(info.get('laravel_version', '')).startswith('13.'), 'application reports Laravel 13')
        checks.require(info.get('php_version') == '8.4', 'application reports PHP 8.4')
        checks.require(SENTINEL not in json.dumps(application), 'application resource excludes inherited business settings')
        url = client.tool('get-absolute-url', {'path': '/health'})
        checks.require(successful(url) and response_text(url) == 'http://localhost/health',
                       'URL generation uses the isolated local environment')
        denied = client.tool('tinker', {'code': 'return 1;'})
        checks.require('error' in denied and denied['error'].get('code') == -32602, 'disabled Tinker invocation is rejected')
        denied = client.tool('database-query', {'query': 'SELECT 1'})
        checks.require('error' in denied and denied['error'].get('code') == -32602, 'database access is unavailable by default')
        checks.require(not marker.exists(), 'inherited configuration cache was never executed')
        checks.require(client.close() == 0, 'MCP exits cleanly after EOF')
    finally:
        if client.process.poll() is None:
            client.kill()


def verify_invalid_configurations(php, directory, checks):
    config = {'DB_HOST': '127.0.0.1', 'DB_PORT': '3306', 'DB_DATABASE': 'jianyun_test',
              'DB_USERNAME': 'boost-test', 'DB_PASSWORD': SENTINEL}
    cases = [
        ('public-permissions', config, 0o644),
        ('business-database', config | {'DB_DATABASE': 'jianyun'}, 0o600),
        ('remote-host', config | {'DB_HOST': 'database.example.invalid'}, 0o600),
        ('invalid-port', config | {'DB_PORT': '0'}, 0o600),
        ('unknown-key', config | {'APP_KEY': SENTINEL}, 0o600),
        ('non-string', config | {'DB_PORT': 3306}, 0o600),
    ]
    for label, data, mode in cases:
        path = Path(directory) / (label + '.json')
        path.write_text(json.dumps(data))
        path.chmod(mode)
        result = subprocess.run([sys.executable, str(WRAPPER), '--php', str(php),
                                 '--database-config', str(path)], cwd=ROOT, input='',
                                text=True, capture_output=True, timeout=10)
        checks.require(result.returncode != 0, 'invalid database configuration rejected: ' + label)
        checks.require(SENTINEL not in result.stdout + result.stderr, 'configuration rejection hides credentials: ' + label)


def verify_disabled(php, directory, checks):
    env = {'PATH': os.defpath, 'APP_ENV': 'local', 'APP_KEY': 'boost-test-only-key',
           'APP_ENVIRONMENT_PATH': str(directory), 'APP_CONFIG_CACHE': str(Path(directory) / 'unused.php'),
           'APP_PACKAGES_CACHE': str(Path(directory) / 'ordinary-packages.php'),
           'APP_SERVICES_CACHE': str(Path(directory) / 'ordinary-services.php')}
    probe = r'''require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
if (getenv('BOOST_CHECK_HTTP') === '1') {
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(
        Illuminate\Http\Request::create('/_boost/browser-logs', 'POST'));
    $status = $response->getStatusCode();
} else { $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $status = null; }
$routes = array_map(fn($route) => $route->uri(), $app['router']->getRoutes()->getRoutes());
echo json_encode(['enabled' => config('boost.enabled'),
    'provider_loaded' => $app->getProvider(Laravel\Boost\BoostServiceProvider::class) !== null,
    'manager_bound' => $app->bound(Laravel\Boost\BoostManager::class),
    'browser_route' => in_array('_boost/browser-logs', $routes, true), 'status' => $status]);'''
    for http in (False, True):
        result = subprocess.run([str(php), '-r', probe], cwd=ROOT,
                                env=env | {'BOOST_CHECK_HTTP': '1' if http else '0',
                                           'APP_RUNNING_IN_CONSOLE': 'false' if http else 'true'},
                                text=True, capture_output=True, timeout=15)
        checks.require(result.returncode == 0, 'ordinary local bootstrap completes')
        try:
            data = json.loads(result.stdout)
        except ValueError:
            raise RuntimeError('Ordinary bootstrap probe returned invalid data') from None
        checks.require(data['provider_loaded'] is True and data['enabled'] is False
                       and data['manager_bound'] is False,
                       'ordinary HTTP/CLI loads the installed Boost provider with its capabilities disabled')
        checks.require(data['browser_route'] is False and (not http or data['status'] == 404),
                       'ordinary application exposes no Boost browser log route')


def verify_cancellation(directory, checks):
    directory = Path(directory)
    pids_path, ready_path = directory / 'cancel-pids.json', directory / 'cancel-ready'
    child = ('import signal,time; from pathlib import Path; '
             f'signal.signal(signal.SIGTERM, signal.SIG_IGN); Path({str(ready_path)!r}).touch(); '
             'time.sleep(60)')
    stub = directory / 'php-process-tree-stub'
    stub.write_text(f'#!{sys.executable}\nimport os,signal,subprocess,sys,time,json\nfrom pathlib import Path\n'
                    'signal.signal(signal.SIGTERM, signal.SIG_IGN)\n'
                    f'child = subprocess.Popen([sys.executable, "-c", {child!r}])\n'
                    f'while not Path({str(ready_path)!r}).exists(): time.sleep(0.02)\n'
                    f'Path({str(pids_path)!r}).write_text(json.dumps([os.getpid(), child.pid]))\n'
                    'time.sleep(60)\n')
    stub.chmod(0o700)
    process = subprocess.Popen([sys.executable, str(WRAPPER), '--php', str(stub)], cwd=ROOT,
                               stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    pids = []

    def running(pid):
        try:
            return Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()[0] != 'Z'
        except FileNotFoundError:
            return False

    try:
        deadline = time.monotonic() + 10
        while not pids_path.exists() and process.poll() is None and time.monotonic() < deadline:
            time.sleep(0.02)
        if pids_path.exists():
            pids = json.loads(pids_path.read_text())
        checks.require(len(pids) == 2 and all(running(pid) for pid in pids), 'cancellation fixture owns a live parent and grandchild')
        process.terminate()
        process.wait(timeout=8)
        checks.require(all(not running(pid) for pid in pids), 'cancellation cleans the PHP group including a TERM-resistant grandchild')
    finally:
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=8)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
        if pids:
            try:
                os.killpg(pids[0], signal.SIGKILL)
            except ProcessLookupError:
                pass


def verify_boost(env, directory, php):
    """Called by the integration runner with its disposable, initialized MySQL database."""
    checks = Checks()
    path = Path(directory) / 'boost-database.json'
    path.write_text(json.dumps({key: str(env[key]) for key in DATABASE_KEYS}))
    path.chmod(0o600)
    inherited, marker = polluted_environment(directory)
    client = MCPClient(php, env=inherited, database_config=path)
    try:
        checks.require(successful(client.initialize()), 'database MCP initialize')
        listed = client.request('tools/list')
        names = {tool['name'] for tool in listed.get('result', {}).get('tools', [])}
        checks.require(names == BASE_TOOLS | DATABASE_TOOLS, 'explicit test database enables only read tools')
        connections = tool_json(client.tool('database-connections'))
        checks.require(connections.get('default_connection') == 'mysql'
                       and 'mysql' in connections.get('connections', []), 'isolated MySQL is the default connection')
        identity = tool_json(client.tool('database-query', {'query': 'SELECT DATABASE() AS database_name, 1 AS probe'}))
        checks.require(identity == [{'database_name': env['DB_DATABASE'], 'probe': 1}], 'query reaches the exact disposable database')
        schema = tool_json(client.tool('database-schema', {'summary': True}))
        checks.require(schema.get('engine') == 'mysql' and len(schema.get('tables', {})) == 27,
                       'MCP schema reports the 27-table baseline')
        checks.require({'users', 'invoices', 'transactions', 'tasks', 'migrations'} <= set(schema['tables']),
                       'MCP schema includes financial and migration tables')
        snapshot_query = ('SELECT COUNT(*) AS row_count, COALESCE(SUM(balance), 0) AS total_balance, '
                          'COALESCE(SUM(auth_version), 0) AS total_auth_version FROM users')
        before = tool_json(client.tool('database-query', {'query': snapshot_query}))
        for query in ('UPDATE users SET auth_version = auth_version + 1',
                      'SET @jianyun_boost_write = 1',
                      'SELECT 1; UPDATE users SET balance = 999999.99'):
            denied = client.tool('database-query', {'query': query})
            checks.require(denied.get('result', {}).get('isError') is True, 'database write is explicitly rejected')
        after = tool_json(client.tool('database-query', {'query': snapshot_query}))
        checks.require(before == after, 'rejected writes leave financial and authentication values unchanged')
        checks.require(not marker.exists(), 'database mode also ignores inherited business configuration cache')
        checks.require(client.close() == 0, 'database MCP exits cleanly')
    finally:
        if client.process.poll() is None:
            client.kill()
        path.unlink(missing_ok=True)
    print(f'Boost database checks: {checks.count}', flush=True)
    return checks.count


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php'), help='PHP 8.4 CLI executable')
    args = parser.parse_args()
    if not args.php:
        parser.error('PHP 8.4 CLI is required')
    checks = Checks()
    with tempfile.TemporaryDirectory(prefix='jianyun-boost-checks-') as directory:
        verify_default(args.php, directory, checks)
        verify_invalid_configurations(args.php, directory, checks)
        verify_disabled(args.php, directory, checks)
        verify_cancellation(directory, checks)
    print(f'Boost checks: {checks.count}', flush=True)


if __name__ == '__main__':
    main()
