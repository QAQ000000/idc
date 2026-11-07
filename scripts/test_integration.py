#!/usr/bin/env python3
"""创建并清理独立 MySQL/Redis/HTTP 环境，不读取部署 .env 或连接现有数据库。"""
import argparse
import base64
import json
import grp
import pwd
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.request


ROOT = Path(__file__).resolve().parents[1]


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--browser', action='store_true', help='Keep an isolated backend + Vite running until Enter')
    parser.add_argument('--runtime', action='store_true', help='Also verify temporary systemd units, timer, restart and journal (root + systemd required)')
    args = parser.parse_args()
    php = shutil.which("php")
    redis = os.getenv("REDIS_SERVER") or shutil.which("redis-server")
    fpm = os.getenv('PHP_FPM') or (str(Path(php).resolve().parent.parent / 'sbin/php-fpm') if php else '')
    if not php or not redis or not shutil.which("docker"):
        raise RuntimeError("Requires php, docker and redis-server (or REDIS_SERVER=/absolute/path)")
    if not Path(fpm).is_file():
        raise RuntimeError('PHP-FPM binary required (or PHP_FPM=/absolute/path)')
    if args.browser and not shutil.which('npm'):
        raise RuntimeError('Browser fixture requires npm and installed web dependencies')
    if args.runtime and (os.geteuid() != 0 or not Path('/run/systemd/system').is_dir()):
        raise RuntimeError('Runtime verification requires root on a running systemd host')
    name = "jianyun-test-" + secrets.token_hex(6)
    env = os.environ.copy()
    redis_port, http_port = free_port(), free_port()
    env.update(
        APP_ENV="test", APP_KEY="base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
        DB_HOST="127.0.0.1", DB_DATABASE="jianyun_test", DB_USERNAME="root",
        DB_PASSWORD=secrets.token_hex(24), REDIS_HOST="127.0.0.1", REDIS_PORT=str(redis_port),
        REDIS_PASSWORD=secrets.token_hex(24), TEST_BASE_URL=f"http://127.0.0.1:{http_port}",
        PHP_CLI_SERVER_WORKERS="4", CORS_ALLOWED_ORIGINS="https://console.example.test",
        TRUSTED_PROXIES="127.0.0.1,::1",
    )
    processes = []
    container_created = False
    with tempfile.TemporaryDirectory(prefix="jianyun-test-") as directory:
        directory = Path(directory)
        env.update(APP_ENVIRONMENT_PATH=str(directory), APP_CONFIG_CACHE=str(directory / 'config.php'))
        try:
            # 密码通过子进程环境传递，不进入命令行参数或日志。
            docker_env = env | {"MYSQL_ROOT_PASSWORD": env["DB_PASSWORD"]}
            subprocess.run([
                "docker", "run", "--detach", "--name", name,
                "--env", "MYSQL_ROOT_PASSWORD", "--env", "MYSQL_DATABASE=jianyun_test",
                "--publish", "127.0.0.1::3306", os.getenv("MYSQL_TEST_IMAGE", "mysql:8.0.35"),
                "--default-time-zone=+00:00",
            ], env=docker_env, check=True, stdout=subprocess.DEVNULL)
            container_created = True
            address = subprocess.check_output(["docker", "port", name, "3306/tcp"], text=True).strip()
            env["DB_PORT"] = address.rsplit(":", 1)[1]
            config = directory / "redis.conf"
            config.write_text(f'bind 127.0.0.1\nport {redis_port}\nsave ""\nappendonly no\nrequirepass {env["REDIS_PASSWORD"]}\n')
            config.chmod(0o600)
            with (directory / "redis.log").open("w") as log:
                processes.append(subprocess.Popen([redis, str(config)], stdout=log, stderr=log, start_new_session=True))
            probe = "require 'bootstrap.php'; try { App\\Core\\Database::getInstance()->query('SELECT 1'); } catch (Throwable) { exit(1); }"
            deadline = time.monotonic() + 90
            while subprocess.run([php, "-r", probe], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
                if time.monotonic() > deadline:
                    raise RuntimeError("Isolated MySQL did not become ready")
                time.sleep(0.3)
            subprocess.run([php, "scripts/console.php", "db:init"], cwd=ROOT, env=env, check=True)
            subprocess.run([php, "tests/unit.php"], cwd=ROOT, env=env, check=True)
            subprocess.run([php, "tests/plugins.php"], cwd=ROOT, env=env, check=True)
            with (directory / "http.log").open("w") as log:
                processes.append(subprocess.Popen([php, "-S", f"127.0.0.1:{http_port}", "-t", "public", "public/index.php"], cwd=ROOT, env=env, stdout=log, stderr=log, start_new_session=True))
            deadline = time.monotonic() + 10
            while True:
                try:
                    with urllib.request.urlopen(env["TEST_BASE_URL"] + "/health", timeout=1) as response:
                        if response.status == 200:
                            break
                except OSError:
                    pass
                if time.monotonic() > deadline:
                    raise RuntimeError("Isolated HTTP server did not become ready")
                time.sleep(0.1)
            result = subprocess.run([php, "tests/integration.php"], cwd=ROOT, env=env)
            if result.returncode:
                raise RuntimeError("Integration checks failed")
            upstream_port = free_port()
            env['UPSTREAM_TEST_URL'] = f'http://127.0.0.1:{upstream_port}'
            with (directory / 'upstream.log').open('w') as log:
                processes.append(subprocess.Popen(['python3', 'tests/upstream_fixture.py', str(upstream_port)], cwd=ROOT, env=env, stdout=log, stderr=log, start_new_session=True))
            deadline = time.monotonic() + 10
            while True:
                try:
                    urllib.request.urlopen(env['UPSTREAM_TEST_URL'] + '/__state', timeout=1).close()
                    break
                except OSError:
                    if time.monotonic() > deadline:
                        raise RuntimeError('Upstream test fixture not ready')
                    time.sleep(0.1)
            subprocess.run([php, 'tests/m2.php'], cwd=ROOT, env=env, check=True)
            subprocess.run([php, 'tests/renewal.php'], cwd=ROOT, env=env, check=True)
            subprocess.run([php, 'tests/configurable.php'], cwd=ROOT, env=env, check=True)
            subprocess.run([php, 'artisan', 'migrate', '--force'], cwd=ROOT, env=env, check=True)
            subprocess.run([php, 'tests/browser_fixture.php'], cwd=ROOT, env=env, check=True)
            fpm_socket = directory / 'fpm.sock'
            fpm_config = directory / 'fpm.conf'
            pool_user = pwd.getpwuid(os.getuid()).pw_name
            pool_group = grp.getgrgid(os.getgid()).gr_name
            # 测试池只监听私有 socket，继承测试进程身份，不复用共享 FPM。
            fpm_config.write_text(f"[global]\ndaemonize=no\nerror_log={directory / 'fpm-error.log'}\n[jianyun-test]\nuser={pool_user}\ngroup={pool_group}\nlisten={fpm_socket}\npm=static\npm.max_children=1\nclear_env=no\ncatch_workers_output=yes\nphp_admin_value[variables_order]=EGPCS\n")
            with (directory / 'fpm.log').open('w') as log:
                processes.append(subprocess.Popen([fpm, '-R', '-F', '-y', str(fpm_config)], env=env, stdout=log, stderr=log, start_new_session=True))
            deadline = time.monotonic() + 10
            while not fpm_socket.exists():
                if time.monotonic() > deadline:
                    raise RuntimeError('Isolated FPM did not become ready')
                time.sleep(0.1)
            from test_fpm import verify
            verify(fpm_socket, ROOT)
            # 配置缓存路径同样隔离；缓存启用后继续验证 HTTP 配置与 Token。
            subprocess.run([php, 'artisan', 'config:cache'], cwd=ROOT, env=env, check=True)
            (directory / 'config.php').chmod(0o600)
            verify(fpm_socket, ROOT)
            if args.runtime:
                from test_runtime import verify_runtime
                verify_runtime(env, directory, php)
            if args.browser:
                vite_port = free_port()
                vite_env = env | {'API_PROXY_TARGET': env['TEST_BASE_URL']}
                with (directory / 'vite.log').open('w') as log:
                    processes.append(subprocess.Popen(['npm', 'run', 'dev', '--', '--port', str(vite_port), '--strictPort'], cwd=ROOT / 'web', env=vite_env, stdout=log, stderr=log, start_new_session=True))
                print(json.dumps({'browser_url': f'http://127.0.0.1:{vite_port}', 'database': env['DB_DATABASE']}), flush=True)
                input('Browser fixture ready. Press Enter to stop and clean up.\n')
            print("Integration environment verified; cleaning up all test resources.")
        except BaseException:
            log_path = directory / 'http.log'
            if log_path.exists():
                print(log_path.read_text()[-6000:])
            raise
        finally:
            for process in reversed(processes):
                try:
                    os.killpg(process.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    process.wait()
            if container_created:
                subprocess.run(["docker", "rm", "--force", "--volumes", name], check=True, stdout=subprocess.DEVNULL)


if __name__ == "__main__":
    main()
