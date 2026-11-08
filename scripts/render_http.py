#!/usr/bin/env python3
"""Render nginx and PHP-FPM configuration only; never install, reload or read secrets."""
import argparse
from pathlib import Path
import grp
import ipaddress
import pwd
import re

ROOT = Path(__file__).resolve().parents[1]


def safe_path(value):
    value = str(Path(value).resolve())
    # One conservative grammar shared by nginx, FPM and their quoted/unquoted directives.
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', value):
        raise ValueError('Use absolute paths containing letters, digits, _, ., / and - only')
    return value


def render(*, name, app, user, web_group, env_dir, storage, socket, cert, cert_key,
           mime_types, log_dir, acme_root, output, domain, fpm, invite_cidr=None,
           bind='0.0.0.0', http_port=80, https_port=443):
    if not re.fullmatch(r'jianyun(?:-[a-z0-9]+)*', name):
        raise ValueError('Invalid environment name')
    if len(domain) > 253 or not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', domain):
        raise ValueError('Use a plain lowercase DNS hostname without scheme, path or port')
    if ipaddress.ip_address(bind).version != 4:
        raise ValueError('The initial template uses an explicit IPv4 listener')
    if not all(isinstance(p, int) and 1 <= p <= 65535 for p in [http_port, https_port]) or http_port == https_port:
        raise ValueError('Invalid listener ports')
    account = pwd.getpwnam(user)
    if account.pw_uid == 0:
        raise ValueError('Production FPM must use a non-root account')
    group = grp.getgrgid(account.pw_gid).gr_name
    grp.getgrnam(web_group)
    paths = dict(APP=app, ENV_DIR=env_dir, STORAGE=storage, FPM_SOCKET=socket, CERT=cert,
                 CERT_KEY=cert_key, MIME_TYPES=mime_types, LOG_DIR=log_dir, ACME_ROOT=acme_root,
                 OUTPUT=output, FPM=fpm)
    values = {k: safe_path(v) for k, v in paths.items()}
    if not (Path(values['APP']) / 'public/index.php').is_file():
        raise ValueError('Application public/index.php is missing')
    values.update(NAME=name, USER=user, GROUP=group, WEB_GROUP=web_group, DOMAIN=domain,
                  BIND=bind, HTTP_PORT=str(http_port), HTTPS_PORT=str(https_port),
                  HTTPS_AUTHORITY=domain + (f':{https_port}' if https_port != 443 else ''),
                  CONFIG_CACHE=values['APP'] + '/bootstrap/cache/config.php', LOG_NAME=name.replace('-', '_'))
    networks = [str(ipaddress.ip_network(cidr, strict=True)) for cidr in (invite_cidr or [])]
    values['INVITE_GATE'] = '\n'.join('    allow ' + n + ';' for n in networks) + ('\n    deny all;' if networks else '')

    def expand(source):
        for key, value in values.items():
            source = source.replace('@' + key + '@', value)
        if re.search(r'@[A-Z_]+@', source):
            raise ValueError('Unresolved template placeholder')
        return source

    values['BACKEND'] = expand((ROOT / 'deploy/nginx/backend.conf.in').read_text()).rstrip()
    target = Path(output)
    target.mkdir(parents=True, exist_ok=True)
    result = []
    for source, filename in [('nginx/site.conf.in', 'site.conf'), ('php-fpm/pool.conf.in', 'pool.conf'),
                             ('php-fpm/master.conf.in', 'master.conf'), ('php-fpm/fpm.service.in', name + '-fpm.service')]:
        path = target / filename
        path.write_text(expand((ROOT / 'deploy' / source).read_text()))
        path.chmod(0o644)
        result.append(path)
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    for option in ['name', 'app', 'user', 'web-group', 'env-dir', 'storage', 'socket', 'cert',
                   'cert-key', 'mime-types', 'log-dir', 'acme-root', 'output', 'domain', 'fpm']:
        parser.add_argument('--' + option, required=True)
    parser.add_argument('--bind', default='0.0.0.0')
    parser.add_argument('--http-port', type=int, default=80)
    parser.add_argument('--https-port', type=int, default=443)
    parser.add_argument('--invite-cidr', action='append', help='Optional invitation network; repeat per network. Callback remains public.')
    for path in render(**vars(parser.parse_args())):
        print(path)
