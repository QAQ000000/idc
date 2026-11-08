#!/usr/bin/env python3
"""Run the dev-only Boost stdio server without inheriting deployment credentials."""
import argparse
import base64
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import stat
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[1]
DATABASE_KEYS = {'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'}


def database_config(path):
    file = Path(path)
    if not stat.S_ISREG(file.stat().st_mode) or file.stat().st_mode & 0o077:
        raise ValueError('Database config must be a private regular file (chmod 600).')
    values = json.loads(file.read_text())
    if not isinstance(values, dict) or set(values) != DATABASE_KEYS:
        raise ValueError('Database config must contain only the five documented DB_* keys.')
    if not all(isinstance(value, str) and '\x00' not in value for value in values.values()):
        raise ValueError('Database config values must be strings without null bytes.')
    if values['DB_HOST'] not in ('127.0.0.1', '::1'):
        raise ValueError('Only a loopback development/test database is supported.')
    if not values['DB_PORT'].isdigit() or not 1 <= int(values['DB_PORT']) <= 65535:
        raise ValueError('Invalid database port.')
    if not re.fullmatch(r'jianyun_(?:test|(?:test|dev)_[A-Za-z0-9_]+)', values['DB_DATABASE']):
        raise ValueError('Use a dedicated jianyun_test / jianyun_test_* / jianyun_dev_* database.')
    if not values['DB_USERNAME'] or not values['DB_PASSWORD']:
        raise ValueError('Development database username and password are required.')
    return values


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php'), help='PHP 8.4 CLI binary')
    parser.add_argument('--database-config', default=os.getenv('JIANYUN_BOOST_DATABASE_CONFIG'),
                        help='Explicit private JSON file for an isolated development/test database')
    args = parser.parse_args()
    try:
        php = Path(args.php).resolve() if args.php else None
        if not php or not php.is_file():
            raise ValueError('PHP CLI is required; specify --php /absolute/path/to/php.')
        if not (ROOT / 'vendor/laravel/boost/src/BoostServiceProvider.php').is_file():
            raise ValueError('Boost is not installed; run composer install including dev dependencies.')
        database = database_config(args.database_config) if args.database_config else {}
    except (OSError, ValueError):
        # Do not render file content, paths or parser exceptions that could contain credentials.
        print('Boost configuration rejected. Use --help and docs/DEVELOPMENT_AGENTS.md.', file=sys.stderr)
        return 2

    with tempfile.TemporaryDirectory(prefix='jianyun-boost-') as directory:
        # An allowlist, not a copy: no inherited DB/Redis/SMTP/upstream secrets or PHP injection flags.
        env = {
            'PATH': str(php.parent) + os.pathsep + os.defpath,
            'LANG': 'C.UTF-8',
            'APP_ENV': 'local', 'APP_DEBUG': 'false', 'APP_URL': 'http://localhost',
            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
            'APP_ENVIRONMENT_PATH': directory,
            'JIANYUN_BOOST_ENABLED': 'true',
            'JIANYUN_BOOST_DATABASE': 'true' if database else 'false',
            'DB_HOST': '127.0.0.1', 'DB_PORT': '1', 'DB_DATABASE': 'jianyun_boost_disabled',
            'DB_USERNAME': 'boost_disabled', 'DB_PASSWORD': secrets.token_hex(24),
            'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': '1', 'REDIS_PASSWORD': secrets.token_hex(24),
        }
        for name in ('CONFIG', 'PACKAGES', 'SERVICES', 'EVENTS', 'ROUTES'):
            env['APP_' + name + '_CACHE'] = str(Path(directory) / (name.lower() + '.php'))
        env.update(database)
        signal.signal(signal.SIGTERM, lambda number, frame: sys.exit(128 + number))
        process = subprocess.Popen([str(php), str(ROOT / 'artisan'), 'boost:mcp'], cwd=ROOT, env=env,
                                   start_new_session=True)
        try:
            return process.wait()
        except KeyboardInterrupt:
            return 130
        finally:
            # Boost executes tools in further PHP subprocesses. Clean the whole owned group,
            # including tools that outlive the main stdio process during cancellation.
            try:
                os.killpg(process.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                pass
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()


if __name__ == '__main__':
    sys.exit(main())
