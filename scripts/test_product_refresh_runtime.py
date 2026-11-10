"""Opt-in oneshot/timer verification, using the disposable test DB and random unit names only."""
import json
import os
from pathlib import Path
import pwd
import secrets
import subprocess
import time
import urllib.request

from render_systemd import render
from test_runtime import environment_file

ROOT = Path(__file__).resolve().parents[1]


def verify_product_refresh_runtime(env, directory, php):
    if env['APP_ENV'] != 'test' or env['DB_DATABASE'] != 'jianyun_test':
        raise RuntimeError('Disposable test DB required')
    env = env.copy()
    name = 'jianyun-refresh-test-' + secrets.token_hex(4)
    service, timer = name + '-products.service', name + '-products.timer'
    installed = []
    checks = 0

    def check(ok, label):
        nonlocal checks
        if not ok:
            raise RuntimeError('FAIL refresh runtime: ' + label)
        checks += 1
        print('PASS refresh runtime: ' + label, flush=True)

    def run(args, allow_failure=False):
        r = subprocess.run(args, cwd=ROOT, env=env, text=True, capture_output=True)
        if r.returncode and not allow_failure:
            raise RuntimeError('Isolated refresh runtime command failed: ' + r.stdout[-2000:] + r.stderr[-1000:])
        return r.stdout

    def fixture(mode, product=None):
        return json.loads(run([php, 'tests/product_refresh_runtime.php', mode] + ([str(product)] if product else [])).splitlines()[-1])

    def upstream(mode):
        urllib.request.urlopen(env['UPSTREAM_TEST_URL'] + '/__config_mode?mode=' + mode, timeout=3).close()

    def wait(predicate, seconds=30):
        end = time.monotonic() + seconds
        while time.monotonic() < end:
            result = predicate()
            if result:
                return result
            time.sleep(.2)
        raise RuntimeError('Timed out in disposable refresh runtime')

    upstream('normal')
    product = fixture('seed')['product_id']
    before = fixture('inspect', product)
    env['PRODUCT_REFRESH_IDS'] = str(product)
    env['APP_CONFIG_CACHE'] = str(directory / 'refresh-runtime-config.php')
    env_file = directory / 'refresh-runtime.env'
    environment_file(env_file, env)
    # This is the same unique source environment, not a credential/configuration copy in Git.
    with env_file.open('a') as output:
        output.write('PRODUCT_REFRESH_IDS="' + str(product) + '"\n')
    paths = render(name, ROOT, php, pwd.getpwuid(os.getuid()).pw_name, env_file,
                   directory / 'refresh-units', product_refresh=True)
    try:
        run(['systemd-analyze', 'verify', *map(str, paths)])
        check(True, 'optional refresh units pass real systemd parser')
        for source in paths:
            if source.name not in [service, timer]:
                continue
            target = Path('/run/systemd/system') / source.name
            if target.exists() or target.is_symlink():
                raise RuntimeError('Refusing to replace existing unit')
            target.symlink_to(source)
            installed.append(target)
        run(['systemctl', 'daemon-reload'])
        upstream('refresh_delay')
        run(['systemctl', 'start', '--no-block', service])
        wait(lambda: fixture('inspect', product)['meta'].get('result') == 'running')
        start = time.monotonic()
        # No business command in this process waits for the separate refresh unit.
        output = run([php, 'tests/runtime_schedule.php', 'run', '2026-11-08 00:01:00'])
        check(time.monotonic() - start < 5 and 'products:refresh' not in output,
              'financial schedule completes while independent refresh is reading supplier')
        run(['systemctl', 'kill', '--signal=SIGKILL', service])
        wait(lambda: run(['systemctl', 'show', service, '-p', 'ActiveState', '--value']).strip() in ['failed', 'inactive'])
        interrupted = fixture('inspect', product)
        check(interrupted['hash'] == before['hash'] and interrupted['meta']['result'] == 'running',
              'oneshot interruption preserves complete old snapshot and unfinished observation')
        upstream('normal')
        fixture('due', product)
        run(['systemctl', 'reset-failed', service])
        run(['systemctl', 'start', service])
        recovered = fixture('inspect', product)
        check(recovered['hash'] == before['hash'] and recovered['meta']['result'] == 'unchanged',
              'new systemd invocation recovers automatically released mutex and publishes no false version')
        fixture('due', product)
        old_success = recovered['meta']['last_success_at']
        run(['systemctl', 'start', timer])
        wait(lambda: fixture('inspect', product)['meta'].get('last_success_at', 0) > old_success, seconds=65)
        run(['systemctl', 'stop', timer])
        check(True, 'natural minute timer starts bounded independent refresh successfully')
        log = run(['journalctl', '-u', service, '--no-pager', '-o', 'cat'])
        check('unchanged' in log and 'config_cache' not in log and 'credentials' not in log,
              'service journal contains result without raw snapshot or credentials')
    finally:
        upstream('normal')
        run(['systemctl', 'stop', timer, service], allow_failure=True)
        for path in installed:
            path.unlink(missing_ok=True)
        run(['systemctl', 'daemon-reload'], allow_failure=True)
        run(['systemctl', 'reset-failed', service, timer], allow_failure=True)
    print('Product refresh runtime checks: ' + str(checks), flush=True)
