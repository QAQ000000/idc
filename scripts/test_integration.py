#!/usr/bin/env python3
"""创建并清理独立 MySQL/Redis/HTTP 环境，不读取部署 .env 或连接现有数据库。"""
import base64
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
    php = shutil.which("php")
    redis = os.getenv("REDIS_SERVER") or shutil.which("redis-server")
    if not php or not redis or not shutil.which("docker"):
        raise RuntimeError("Requires php, docker and redis-server (or REDIS_SERVER=/absolute/path)")
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
            print("Integration environment verified; cleaning up all test resources.")
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
