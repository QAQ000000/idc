"""Opt-in systemd acceptance against the disposable integration DB and HTTP fixture only."""
import json
import os
from pathlib import Path
import pwd
import secrets
import subprocess
import time
import urllib.request

from render_systemd import render

ROOT = Path(__file__).resolve().parents[1]


def environment_file(path, env):
    # Pass only application configuration, never unrelated shell credentials or tokens.
    keys = ['APP_ENV', 'APP_KEY', 'APP_ENVIRONMENT_PATH', 'APP_CONFIG_CACHE',
            'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'TRUSTED_PROXIES', 'CORS_ALLOWED_ORIGINS', 'LARAVEL_STORAGE_PATH']
    lines = []
    for key in keys:
        value = env.get(key, '')
        if '\n' in value or '\r' in value:
            raise ValueError('Unexpected newline in test environment')
        lines.append(key + '="' + value.replace('\\', '\\\\').replace('"', '\\"') + '"')
    path.touch(mode=0o600, exist_ok=False)
    path.write_text('\n'.join(lines) + '\n')


def verify_runtime(env, directory, php):
    if env['APP_ENV'] != 'test' or env['DB_DATABASE'] != 'jianyun_test':
        raise RuntimeError('Disposable runtime DB required')
    env = env.copy()
    env['LARAVEL_STORAGE_PATH'] = str(directory / 'runtime-storage')
    (directory / 'runtime-storage' / 'logs').mkdir(parents=True)
    name = 'jianyun-runtime-test-' + secrets.token_hex(4)
    units = [name + '-worker.service', name + '-schedule.service', name + '-schedule.timer', name + '-mail.service']
    worker, scheduler, timer, mailer = units
    installed = []
    checks = 0

    def check(value, message):
        nonlocal checks
        if not value:
            raise RuntimeError('FAIL runtime: ' + message)
        checks += 1
        print('PASS runtime: ' + message, flush=True)

    def command(args):
        result = subprocess.run(args, cwd=ROOT, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if result.returncode:
            raise RuntimeError('Disposable runtime command failed: ' + result.stdout[-4000:])
        return result.stdout

    def fixture(mode='snapshot', *args):
        return json.loads(command([php, 'tests/runtime.php', mode, *map(str, args)]).splitlines()[-1])

    def upstream(path='/__state'):
        with urllib.request.urlopen(env['UPSTREAM_TEST_URL'] + path, timeout=3) as r:
            return json.load(r)

    def wait_for(predicate, seconds=20):
        end = time.monotonic() + seconds
        while time.monotonic() < end:
            result = predicate()
            if result:
                return result
            time.sleep(0.2)
        raise RuntimeError('Timed out waiting for disposable runtime state')

    def renewals(state):
        return [v for v in state['invoices'] if v['type'] == 'renew']

    def task(state, invoice):
        return next(t for t in state['tasks'] if int(t['invoice_id']) == int(invoice))

    def prop(unit, key):
        return command(['systemctl', 'show', unit, '--property=' + key, '--value']).strip()

    env_path = directory / 'runtime.env'
    environment_file(env_path, env)
    paths = render(name, ROOT, php, pwd.getpwuid(os.getuid()).pw_name, env_path, directory / 'units')
    try:
        command(['systemd-analyze', 'verify', *map(str, paths)])
        check(True, 'rendered units pass the real systemd parser')
        for source in paths:
            target = Path('/run/systemd/system') / source.name
            if target.exists() or target.is_symlink():
                raise RuntimeError('Refusing to replace an existing service')
            target.symlink_to(source)
            installed.append(target)
        command(['systemctl', 'daemon-reload'])
        upstream('/__renew_mode?mode=normal')
        baseline = fixture('seed')
        check(not renewals(baseline), 'runtime fixtures start without renewal invoices')
        expected = {1: ['invoices:expire'], 0: ['invoices:expire', 'renewals:generate', 'renewals:debit'],
                    15: ['invoices:expire', 'renewals:debit'], 30: ['invoices:expire', 'renewals:debit'],
                    45: ['invoices:expire', 'renewals:debit']}
        for due in expected.values():
            due.append('notifications:sweep')
        for minute, due in expected.items():
            actual = json.loads(command([php, 'tests/runtime_schedule.php', 'due', f'2026-11-08 00:{minute:02}:00']))
            check(actual == due, f'UTC minute {minute:02} selects only its due Laravel commands')
        command([php, 'tests/runtime_schedule.php', 'run', '2026-11-08 00:01:00'])
        check(not renewals(fixture()), 'non-hour schedule does not issue premature renewal invoices')
        command([php, 'tests/runtime_notifications.php', 'seed'])
        command([php, 'artisan', 'notifications:sweep'])
        mail_state = json.loads(command([php, 'tests/runtime_notifications.php', 'snapshot']).splitlines()[-1])
        check(mail_state['status'] == 'pending_manual_check', 'scheduler quarantines an expired mail sending claim without a resend')
        command(['systemctl', 'start', mailer])
        mail_pid = prop(mailer, 'MainPID')
        check(int(mail_pid) > 0 and prop(mailer, 'ActiveState') == 'active', 'independent mail sender starts under systemd')
        command(['systemctl', 'kill', '--kill-who=main', '--signal=SIGKILL', mailer])
        wait_for(lambda: prop(mailer, 'ActiveState') == 'active' and prop(mailer, 'MainPID') not in ('0', mail_pid))
        check(json.loads(command([php, 'tests/runtime_notifications.php', 'snapshot']).splitlines()[-1]) == mail_state,
              'mail sender restarts without reclaiming or overwriting an unknown mail outcome')
        output = command([php, 'tests/runtime_schedule.php', 'run', '2026-11-08 00:00:00'])
        state = fixture()
        check(len(renewals(state)) == 2 and sorted(v['status'] for v in renewals(state)) == ['paid', 'unpaid'],
              'scheduled hourly billing and quarter-hour debit honor automatic opt-out')
        check('Renewal invoices checked:' in output and 'Renewal invoices paid: 1' in output,
              'scheduled child output reaches stdout for journal collection')
        paid = next(v['id'] for v in renewals(state) if v['status'] == 'paid')
        balance, due = state['balance'], state['instances'][0]['due_date']
        command([php, 'tests/runtime_schedule.php', 'run', '2026-11-08 00:00:00'])
        check(fixture()['balance'] == balance and len(renewals(fixture())) == 2,
              'replayed scheduling cannot duplicate a period or wallet debit')

        # SIGKILL after upstream payment, then let the actual supervisor restart the Worker.
        upstream('/__renew_mode?mode=hold_payment')
        before = upstream()
        command(['systemctl', 'start', worker])
        first_pid = prop(worker, 'MainPID')
        wait_for(lambda: upstream()['renew_payments'] == before['renew_payments'] + 1)
        claim = task(fixture(), paid)
        check(claim['status'] == 'running', 'managed Worker holds the paid renewal while upstream response is pending')
        command(['systemctl', 'kill', '--kill-who=main', '--signal=SIGKILL', worker])
        new_pid = wait_for(lambda: (p if (p := prop(worker, 'MainPID')) not in ('0', first_pid) else False))
        check(new_pid != first_pid and int(prop(worker, 'NRestarts')) >= 1,
              'systemd automatically restarts a killed Worker')
        check(task(fixture(), paid)['execution_token'] == claim['execution_token'],
              'restart does not steal a live lease or replay the paid request')
        fixture('expire-lease', claim['id'])  # Accelerate only this isolated DB lease; never change host time.
        wait_for(lambda: task(fixture(), paid)['status'] == 'success')
        recovered = fixture()
        check(upstream()['renew_calls'] == before['renew_calls'] + 1 and upstream()['renew_payments'] == before['renew_payments'] + 1,
              'expired-lease recovery queries existing upstream delivery without another renewal or payment')
        check(recovered['balance'] == balance and recovered['instances'][0]['due_date'] == due
              and task(recovered, paid)['execution_token'] > claim['execution_token'],
              'recovery fences the old claim and preserves money and the original target expiry')

        # Journal receives real application warnings/critical records; manual task survives service restart.
        upstream('/__renew_mode?mode=drop_submit')
        state = fixture('renew')
        unknown = max(v['id'] for v in renewals(state))
        wait_for(lambda: task(fixture(), unknown)['status'] == 'investigating')
        fixture('manual', task(fixture(), unknown)['id'])
        wait_for(lambda: task(fixture(), unknown)['status'] == 'failed')
        manual = task(fixture(), unknown)
        journal = command(['journalctl', '-u', worker, '--no-pager', '-o', 'cat'])
        check('Paid renewal requires upstream verification' in journal and 'Outbox manual review required' in journal
              and f'"invoice_id":{unknown}' in journal, 'journal collects actionable renewal warning and critical logs')
        command(['systemctl', 'restart', worker])
        listed = json.loads(command([php, 'artisan', 'outbox:inspect']))
        check(any(t['id'] == manual['id'] and t['action'] == 'renew' for t in listed)
              and task(fixture(), unknown)['status'] == 'failed' and fixture()['refunds'] == 0,
              'operator task survives a service restart without refunding or retrying unknown execution')

        # The already running supervisor consumes a post-commit hint without a timer tick.
        state = fixture()
        waiting = next(v['id'] for v in renewals(state) if v['status'] == 'unpaid')
        wallet_before = state['balance']
        upstream('/__renew_mode?mode=normal')
        fixture('wakeup-fund')
        wait_for(lambda: next(v for v in renewals(fixture()) if v['id'] == waiting)['status'] == 'paid')
        wait_for(lambda: task(fixture(), waiting)['status'] == 'success')
        check(fixture()['balance'] == wallet_before
              and len([t for t in fixture()['tasks'] if int(t['invoice_id']) == waiting]) == 1,
              'managed Worker wakes on committed recharge and settles the original renewal before a timer tick')

        # A real calendar tick runs the unchanged schedule:run entry, not the controlled clock wrapper.
        state = fixture('expire-order')
        expired = max(v['id'] for v in state['invoices'] if v['type'] == 'new')
        fixture('fail-expiration')
        try:
            output = command([php, 'tests/runtime_schedule.php', 'run', '2026-11-08 00:01:00'])
            check('Scheduled command failed' in output and 'invoices:expire' in output
                  and next(v for v in fixture()['invoices'] if v['id'] == expired)['status'] == 'unpaid',
                  'failed scheduled child emits a named error and rolls back its business transition')
        finally:
            fixture('clear-expiration-fault')
        command(['systemctl', 'start', timer])
        check(prop(timer, 'ActiveState') == 'active' and prop(timer, 'NextElapseUSecRealtime') != '',
              'the real minute timer is armed')
        wait_for(lambda: next(v for v in fixture()['invoices'] if v['id'] == expired)['status'] == 'cancelled', seconds=75)
        check(int(prop(scheduler, 'ExecMainStatus')) == 0, 'a real minute tick runs schedule:run and expires an unpaid invoice')
        journal = command(['journalctl', '-u', scheduler, '--no-pager', '-o', 'cat'])
        check('Processed ' in journal and ' expired invoices.' in journal,
              'scheduler journal retains child command completion output')
        print(f'Runtime checks: {checks}', flush=True)
    finally:
        if installed:
            subprocess.run(['systemctl', 'stop', timer, worker, scheduler, mailer], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            for target in installed:
                target.unlink(missing_ok=True)
            subprocess.run(['systemctl', 'daemon-reload'], check=True)
            subprocess.run(['systemctl', 'reset-failed', *units], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
