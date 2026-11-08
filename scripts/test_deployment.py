#!/usr/bin/env python3
"""Fresh production-mode installation inside test_integration's disposable infrastructure."""
import base64
import grp
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import signal
import ssl
import subprocess
import time
import urllib.error
import urllib.request

from render_http import render
from render_systemd import render as render_units
from test_integration import ROOT, free_port


def verify_deployment(parent_env, directory, php, fpm, container):
    if parent_env.get('APP_ENV') != 'test' or parent_env.get('DB_DATABASE') != 'jianyun_test' or not container.startswith('jianyun-test-'):
        raise RuntimeError('Requires the disposable integration environment')
    nginx = shutil.which('nginx')
    composer = [php, os.environ['COMPOSER_BIN']] if os.getenv('COMPOSER_BIN') else ['composer']
    if os.geteuid() != 0 or not Path('/run/systemd/system').is_dir() or not nginx or not (os.getenv('COMPOSER_BIN') or shutil.which('composer')) or not shutil.which('npm'):
        raise RuntimeError('Deployment rehearsal requires root, systemd, nginx, composer, npm and openssl')
    processes = []
    installed_units = []
    checks = 0
    private_values = [parent_env.get(k, '') for k in ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD']]

    def check(value, message):
        nonlocal checks
        if not value:
            raise RuntimeError('Deployment rehearsal: ' + message)
        checks += 1
        print('PASS deployment: ' + message, flush=True)

    def command(args, *, env=None, cwd=None, input=None, expected=True):
        result = subprocess.run(args, cwd=cwd, env=env, input=input, text=True, capture_output=True)
        if expected and result.returncode:
            # Never echo private stdin, SMTP bodies, database dumps or environment values.
            diagnostic = (result.stdout + result.stderr)[-2400:]
            for secret in private_values:
                if secret:
                    diagnostic = diagnostic.replace(secret, '[redacted]')
            raise RuntimeError('Deployment command failed: ' + Path(args[0]).name + '\n' + diagnostic)
        return result

    def root_sql(sql):
        code = "$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'));$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);foreach(json_decode(stream_get_contents(STDIN),true) as $s){$p->exec($s);}"
        command([php, '-r', code], env=parent_env, input=json.dumps(sql))

    directory.chmod(0o755)
    work = directory / 'release-rehearsal'
    work.mkdir(mode=0o755)
    release = work / 'app'
    release.mkdir()
    account = pwd.getpwnam('nobody')
    group = grp.getgrgid(account.pw_gid).gr_name
    envdir, storage, logs = [work / name for name in ['private', 'storage', 'logs']]
    for path in [envdir, storage, logs]:
        path.mkdir(mode=0o750)
        os.chown(path, account.pw_uid, account.pw_gid)
    # Master logs/PID are root-owned; an application process cannot replace them.
    os.chown(logs, 0, 0)
    logs.chmod(0o755)
    # Build a source-only release. Never copy vendor, node_modules, .env or M2 artifacts.
    sources = set(command(['git', 'ls-files', '-z'], cwd=ROOT).stdout.strip('\0').split('\0'))
    sources.update(str(p.relative_to(ROOT)) for p in (ROOT / 'deploy').rglob('*.in'))
    sources.update(['scripts/render_http.py', 'tests/deployment.php', 'tests/smtp_fixture.py'])
    for name in sources:
        source = ROOT / name
        if source.is_file():
            target = release / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
    dbpass, adminpass, paykey = [secrets.token_hex(20) for _ in range(3)]
    private_values.extend([dbpass, adminpass, paykey])
    # Only this disposable MySQL container: allow reviewed schema triggers without
    # giving the application's database account global SUPER privileges.
    root_sql(['SET GLOBAL log_bin_trust_function_creators=1',
              'CREATE DATABASE jianyun_release_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
              "CREATE USER 'release_test'@'%' IDENTIFIED BY '" + dbpass + "'",
              "GRANT ALL ON jianyun_release_test.* TO 'release_test'@'%'"])
    values = {k: parent_env[k] for k in ['DB_HOST', 'DB_PORT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD']}
    http_port, https_port = free_port(), free_port()
    url = f'https://127.0.0.1:{https_port}'
    values.update(APP_ENV='production', APP_DEBUG='false', APP_URL=url,
                  APP_KEY='base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
                  DB_CONNECTION='mysql', DB_DATABASE='jianyun_release_test', DB_USERNAME='release_test',
                  DB_PASSWORD=dbpass, REDIS_DB='1', CORS_ALLOWED_ORIGINS='', TRUSTED_PROXIES='', CONSOLE_TARGETS='')
    private_values.append(values['APP_KEY'])
    private = envdir / '.env'
    private.write_text(''.join(k + '=' + v + '\n' for k, v in values.items()))
    os.chown(private, account.pw_uid, account.pw_gid)
    private.chmod(0o600)
    cleanenv = {k: v for k, v in os.environ.items() if not k.startswith(('APP_', 'DB_', 'REDIS_', 'CONSOLE_', 'CORS_', 'TRUSTED_', 'LARAVEL_'))}
    cleanenv.update(APP_ENVIRONMENT_PATH=str(envdir), APP_CONFIG_CACHE=str(release / 'bootstrap/cache/config.php'), LARAVEL_STORAGE_PATH=str(storage),
                    DEPLOYMENT_REHEARSAL='1', RELEASE_URL=url, RELEASE_ADMIN_PASSWORD=adminpass, RELEASE_PAYMENT_KEY=paykey)
    for part in ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs']:
        (storage / part).mkdir(parents=True, exist_ok=True)
    for path in storage.rglob('*'):
        os.chown(path, account.pw_uid, account.pw_gid)
    for path in [release / 'bootstrap/cache']:
        os.chown(path, account.pw_uid, account.pw_gid)
        path.chmod(0o750)

    def artisan(*args, input=None, expected=True):
        return command(['runuser', '-u', 'nobody', '--', php, 'artisan', *args, '--no-interaction'], env=cleanenv, cwd=release, input=input, expected=expected)

    def phase(name):
        result = command(['runuser', '-u', 'nobody', '--', php, 'tests/deployment.php', name], cwd=release, env=cleanenv)
        print(result.stdout, end='', flush=True)
        check(re.search(r'Deployment phase checks: \d+', result.stdout) is not None, name + ' phase reached its summary')

    cert, key = work / 'tls.crt', work / 'tls.key'
    command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=localhost',
             '-addext', 'subjectAltName=IP:127.0.0.1,DNS:localhost', '-keyout', str(key), '-out', str(cert)])
    key.chmod(0o600)
    cleanenv.update(RELEASE_CERT=str(cert), PHP_INI_SCAN_DIR=os.getenv('PHP_INI_SCAN_DIR', ''))
    # CLI mail verification trusts only the local fixture CA in addition to normal PHP settings.
    ini = work / 'tls.ini'
    ini.write_text('openssl.cafile=' + str(cert) + '\ncurl.cainfo=' + str(cert) + '\n')
    default_ini = command([php, '--ini']).stdout
    match = re.search(r'Scan for additional .ini files in:\s*(\S+)', default_ini)
    cleanenv['PHP_INI_SCAN_DIR'] = (match.group(1) + ':' if match and match.group(1) != '(none)' else '') + str(ini.parent / 'ini')
    (ini.parent / 'ini').mkdir()
    shutil.move(ini, ini.parent / 'ini/local-tls.ini')
    try:
        command([*composer, 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--optimize-autoloader'], cwd=release, env=cleanenv | {'COMPOSER_ALLOW_SUPERUSER': '1'})
        command([*composer, 'check-platform-reqs', '--no-dev'], cwd=release, env=cleanenv)
        check((release / 'vendor/autoload.php').is_file(), 'clean release installs production dependencies from composer.lock')
        command(['npm', 'ci', '--no-audit', '--no-fund'], cwd=release / 'web', env=cleanenv)
        command(['npm', 'run', 'build'], cwd=release / 'web', env=cleanenv)
        check((release / 'web/dist/index.html').is_file(), 'clean frontend lockfile install and release build succeed')
        artisan('db:init')
        phase('empty')
        result = artisan('db:init', expected=False)
        check(result.returncode != 0 and 'requires an empty database' in result.stdout + result.stderr, 'db:init refuses the initialized database')
        artisan('migrate', '--force')
        administrator = json.dumps(dict(username='release-admin', email='release-admin@example.test', password=adminpass, role='super_admin'))
        check('admin_id' in artisan('admin:create', input=administrator).stdout, 'private stdin initializes the administrator')
        check(artisan('admin:create', input=administrator, expected=False).returncode != 0, 'administrator provisioning cannot overwrite an existing login')
        upstream_url = f'http://127.0.0.1:{free_port()}'
        with (logs / 'upstream.log').open('w') as log:
            processes.append(subprocess.Popen(['python3', str(ROOT / 'tests/upstream_fixture.py'), upstream_url.rsplit(':', 1)[1]], stdout=log, stderr=log, start_new_session=True))
        deadline = time.monotonic() + 10
        while True:
            try:
                urllib.request.urlopen(upstream_url + '/__state', timeout=1).close()
                break
            except OSError:
                if time.monotonic() > deadline:
                    raise RuntimeError('Release upstream fixture unavailable')
                time.sleep(0.1)
        setup = json.loads((ROOT / 'deploy/production/connections.json.in').read_text())
        setup['provider'].update(name='Release fixture', endpoint=upstream_url, credentials={'username': 'fixture', 'password': 'fixture'})
        setup['product'].update(name='Release fixture CDN')
        setup['payment'].update(endpoint='https://pay.example.test', pid='release-merchant', notify_url=url + '/api/v1/payments/epay/notify', return_url=url, credentials={'key': paykey})
        check('product_id' in artisan('connections:install', input=json.dumps(setup)).stdout, 'existing setup service installs only the local upstream fixture')
        smtp_port, smtp_control = free_port(), free_port()
        with (logs / 'smtp.log').open('w') as log:
            processes.append(subprocess.Popen(['python3', str(ROOT / 'tests/smtp_fixture.py'), str(smtp_port), str(smtp_control), str(cert), str(key)], stdout=log, stderr=log, start_new_session=True))
        mail = json.loads((ROOT / 'deploy/production/mail.json.in').read_text())
        mail.update(site_url=url)
        mail['config'].update(host='127.0.0.1', port=smtp_port, username='', password='')
        artisan('notifications:configure', '1', input=json.dumps(mail))
        artisan('config:cache')
        cache = release / 'bootstrap/cache/config.php'
        cache.chmod(0o600)
        check(cache.exists() and not (release / '.env').exists(), 'HTTP and CLI share external configuration; cached secrets stay private')
        mime = next((p for p in [Path('/etc/nginx/mime.types'), Path('/www/server/nginx/conf/mime.types')] if p.is_file()))
        conf = work / 'http'
        sock = work / 'fpm.sock'
        render(name='jianyun-release-test', app=release, user='nobody', web_group=group, env_dir=envdir, storage=storage,
               socket=sock, cert=cert, cert_key=key, mime_types=mime, log_dir=logs, acme_root=work / 'acme', output=conf,
               domain='localhost', fpm=fpm, bind='127.0.0.1', http_port=http_port, https_port=https_port, invite_cidr=['127.0.0.1/32'])
        # A separate foreground master, with no shared nginx reload or port 80/443 binding.
        nginxconf = work / 'nginx.conf'
        # This host's panel nginx bundles Lua; its libraries must still be found
        # when the test uses a private prefix. Plain nginx needs no Lua directive.
        lua = ''
        if 'lua' in command([nginx, '-V']).stderr and Path('/www/server/nginx/lib/lua/resty/core.lua').is_file():
            lua = 'lua_package_path "/www/server/nginx/lib/lua/?.lua;;"; '
        nginxconf.write_text(f'user nobody {group};\npid {work}/nginx.pid;\nerror_log {logs}/nginx-master.log;\nevents {{ worker_connections 64; }}\nhttp {{ {lua}client_body_temp_path {logs}/body; fastcgi_temp_path {logs}/fastcgi; include {conf}/site.conf; }}\n')
        command([nginx, '-t', '-p', str(work), '-c', str(nginxconf)])
        command([fpm, '-t', '-y', str(conf / 'master.conf')], env=cleanenv)
        unit = conf / 'jianyun-release-test-fpm.service'
        command(['systemd-analyze', 'verify', str(unit)])
        check(True, 'rendered nginx, dedicated FPM master and FPM systemd unit parse successfully')
        for args in [[fpm, '-F', '-y', str(conf / 'master.conf')], [nginx, '-p', str(work), '-c', str(nginxconf), '-g', 'daemon off;']]:
            with (logs / ('process-' + str(len(processes)) + '.log')).open('w') as log:
                processes.append(subprocess.Popen(args, env=cleanenv, stdout=log, stderr=log, start_new_session=True))
        tls = ssl.create_default_context(cafile=str(cert))

        def request(path):
            try:
                return urllib.request.urlopen(url + path, context=tls, timeout=5)
            except urllib.error.HTTPError as error:
                return error

        deadline = time.monotonic() + 15
        while True:
            try:
                if request('/health').status == 200:
                    break
            except OSError:
                pass
            if time.monotonic() > deadline:
                diagnostic = '\n'.join(p.name + ': ' + p.read_text()[-1400:] for p in logs.glob('*.log') if p.name != 'smtp.log')
                for secret in private_values:
                    if secret:
                        diagnostic = diagnostic.replace(secret, '[redacted]')
                raise RuntimeError('Isolated HTTPS/FPM did not become ready\n' + diagnostic)
            time.sleep(0.1)
        check(json.loads(request('/health').read())['data']['status'] == 'ok', 'HTTPS reaches the fresh production database through FPM')
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None

        try:
            redirected = urllib.request.build_opener(NoRedirect()).open(f'http://127.0.0.1:{http_port}/health', timeout=5)
        except urllib.error.HTTPError as error:
            redirected = error
        check(redirected.status == 308 and redirected.headers['Location'] == f'https://localhost:{https_port}/health', 'HTTP redirects to the configured HTTPS authority')
        for path in ['/admin/tasks/3', '/instances/1', '/reset-password']:
            response = request(path)
            check(response.status == 200 and b'<html' in response.read(), 'SPA deep link works: ' + path)
        index = request('/').read().decode()
        asset = re.search(r'src="(/assets/[^\"]+\.js)"', index).group(1)
        check('javascript' in request(asset).headers.get('Content-Type', ''), 'built assets have the correct MIME type')
        for path in ['/.env', '/public/index.php', '/assets/missing.js']:
            check(request(path).status == 404, 'private or nonexistent file denied: ' + path)
        response = request('/api/v1/does-not-exist')
        check(response.status == 404 and 'application/json' in response.headers.get('Content-Type', ''), 'API 404 never falls back to SPA HTML')
        phase('web')
        # Use the real non-root service templates, with the same external .env as FPM.
        unit_name = 'jianyun-release-test-' + secrets.token_hex(4)
        runtime = work / 'runtime.env'
        runtime.write_text(''.join(k + '=' + cleanenv[k] + '\n' for k in
                                  ['APP_ENVIRONMENT_PATH', 'APP_CONFIG_CACHE', 'LARAVEL_STORAGE_PATH', 'PHP_INI_SCAN_DIR']))
        runtime.chmod(0o600)
        units = render_units(unit_name, release, php, 'nobody', runtime, work / 'units')
        command(['systemd-analyze', 'verify', *map(str, units)])
        for unit in units:
            target = Path('/run/systemd/system') / unit.name
            if target.exists() or target.is_symlink():
                raise RuntimeError('Refusing to replace another runtime unit')
            target.symlink_to(unit)
            installed_units.append(target)
        command(['systemctl', 'daemon-reload'])
        command(['systemctl', 'start', unit_name + '-worker.service', unit_name + '-mail.service', unit_name + '-schedule.timer'])
        command(['systemctl', 'start', unit_name + '-schedule.service'])
        check(command(['systemctl', 'show', unit_name + '-worker.service', '--property=User', '--value']).stdout.strip() == 'nobody', 'fresh production Worker is managed by systemd as a non-root account')
        deadline = time.monotonic() + 20
        while True:
            ready = command([php, '-r', "require 'bootstrap.php';$d=App\\Core\\Database::getInstance();echo $d->query(\"SELECT COUNT(*) FROM tasks WHERE status='success'\")->fetchColumn().':'.$d->query(\"SELECT COUNT(*) FROM notification_deliveries WHERE status='success'\")->fetchColumn();"], cwd=release, env=cleanenv).stdout
            if ready == '1:1':
                break
            if time.monotonic() > deadline:
                raise RuntimeError('Fresh non-root Worker/mail services did not finish their fixture work')
            time.sleep(0.2)
        check(command(['systemctl', 'show', unit_name + '-schedule.service', '--property=Result', '--value']).stdout.strip() == 'success', 'fresh schedule unit executes using the production environment paths')
        check(command(['systemctl', 'is-active', unit_name + '-schedule.timer']).stdout.strip() == 'active', 'fresh minute timer is armed independently of M2')
        phase('delivered')
        state = json.load(urllib.request.urlopen(f'http://127.0.0.1:{smtp_control}/state'))
        check(state['data_attempts'] == 1 and len(state['messages']) == 1, 'exactly one acceptance email reaches isolated TLS SMTP, with no external recipient')
        command(['systemctl', 'stop', *[p.name for p in installed_units]])
        # A restorable database backup needs its original APP_KEY, not a freshly generated key.
        snapshot_code = "require 'bootstrap.php';$d=App\\Core\\Database::getInstance();$s=[];foreach($d->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){$s[$t]=$d->query('SELECT * FROM `'.$t.'` ORDER BY 1')->fetchAll();}echo hash('sha256',json_encode($s));"
        before = command([php, '-r', snapshot_code], cwd=release, env=cleanenv).stdout
        dumpenv = parent_env | {'MYSQL_PWD': parent_env['DB_PASSWORD']}
        dump = command(['docker', 'exec', '-e', 'MYSQL_PWD', container, 'mysqldump', '-uroot', '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', 'jianyun_release_test'], env=dumpenv).stdout
        root_sql(['CREATE DATABASE jianyun_restore_test', "GRANT ALL ON jianyun_restore_test.* TO 'release_test'@'%'"])
        command(['docker', 'exec', '-i', '-e', 'MYSQL_PWD', container, 'mysql', '-uroot', 'jianyun_restore_test'], env=dumpenv, input=dump)
        restoreenv = cleanenv | {'APP_CONFIG_CACHE': str(work / 'no-cache.php'), 'DB_DATABASE': 'jianyun_restore_test'}
        restored = command([php, '-r', snapshot_code + "if(!(new App\\Services\\NotificationSettingsService())->load() || (int)$d->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn()!==5){exit(1);}"], cwd=release, env=restoreenv).stdout
        check(before == restored and len(before) == 64, 'backup restores all table contents, guards and encrypted settings into an empty database with the original APP_KEY')
        # Re-render with a disallowed invitation IP to prove the callback is exempt.
        config = (conf / 'site.conf').read_text().replace('allow 127.0.0.1/32;', 'allow 192.0.2.1/32;')
        (conf / 'site.conf').write_text(config)
        command([nginx, '-t', '-p', str(work), '-c', str(nginxconf)])
        os.kill(int((work / 'nginx.pid').read_text()), signal.SIGHUP)
        deadline = time.monotonic() + 5
        while request('/').status != 403 and time.monotonic() < deadline:
            time.sleep(0.1)
        check(request('/').status == 403 and request('/api/v1/admin/tasks').status == 403, 'invitation IP gate blocks both SPA and customer/admin API')
        check(request('/api/v1/payments/epay/notify').status == 400, 'uninvited gateway reaches signature validation instead of being blocked')
        check('release-trade-1' not in (logs / 'access.log').read_text(), 'access log omits callback query parameters')
        print(f'Deployment rehearsal checks: {checks} (plus PHP phase checks)', flush=True)
    finally:
        if installed_units:
            subprocess.run(['systemctl', 'stop', *[p.name for p in installed_units]], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(['systemctl', 'reset-failed', *[p.name for p in installed_units]], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            for path in installed_units:
                path.unlink()
            subprocess.run(['systemctl', 'daemon-reload'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for process in reversed(processes):
            try:
                os.killpg(process.pid, signal.SIGTERM)
                process.wait(timeout=8)
            except ProcessLookupError:
                pass
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
