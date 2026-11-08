#!/usr/bin/env python3
"""Render project-specific systemd units. Never installs, starts, or reads secrets."""
import argparse
from pathlib import Path
import pwd
import re

ROOT = Path(__file__).resolve().parents[1]


def quoted(value, *, executable=False):
    if any(ord(c) < 32 or ord(c) == 127 for c in value):
        raise ValueError('Control characters are not allowed in systemd paths')
    value = value.replace('\\', '\\\\').replace('"', '\\"').replace('%', '%%')
    if executable:
        value = value.replace('$', '$$')
    return '"' + value + '"'


def render(name, app, php, user, env_file, output, console=False):
    if not re.fullmatch(r'jianyun(?:-[a-z0-9]+)*', name):
        raise ValueError('Name must be jianyun or jianyun-<lowercase letters/digits>')
    if not re.fullmatch(r'[a-z_][a-z0-9_-]*\$?', user):
        raise ValueError('Invalid service user')
    pwd.getpwnam(user)
    app, php, env_file, output = (Path(p).resolve() for p in (app, php, env_file, output))
    if not (app / 'artisan').is_file() or not php.is_file() or not env_file.is_file():
        raise ValueError('Application artisan, PHP executable and environment file must exist')
    # These directives take path values, while ExecStart parses a quoted argument list.
    quoted(str(env_file))  # Validate control characters before emitting a unit directive.
    values = {'NAME': name, 'USER': user, 'APP_DIR': str(app).replace('%', '%%'),
              'ENV_FILE': str(env_file).replace('%', '%%'), 'PHP': quoted(str(php), executable=True),
              'ARTISAN': quoted(str(app / 'artisan'), executable=True),
              'RELAY': quoted(str(app / 'scripts/console_relay.php'), executable=True)}
    output.mkdir(parents=True, exist_ok=True)
    paths = []
    sources = [('worker.service.in', 'worker.service'), ('schedule.service.in', 'schedule.service'), ('schedule.timer.in', 'schedule.timer')]
    if console:
        sources.append(('console.service.in', 'console.service'))
    for source, suffix in sources:
        text = (ROOT / 'deploy/systemd' / source).read_text()
        for key, value in values.items():
            text = text.replace('@' + key + '@', value)
        target = output / (name + '-' + suffix)
        target.write_text(text)
        target.chmod(0o644)
        paths.append(target)
    return paths


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--name', required=True)
    p.add_argument('--app', required=True)
    p.add_argument('--php', required=True)
    p.add_argument('--user', required=True)
    p.add_argument('--env-file', required=True)
    p.add_argument('--output', required=True)
    p.add_argument('--console', action='store_true', help='Also render the optional same-site console relay')
    a = p.parse_args()
    for path in render(a.name, a.app, a.php, a.user, a.env_file, a.output, a.console):
        print(path)
