#!/usr/bin/env python3
"""Build immutable cPanel candidates; never creates a production-ready assertion."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

VARIANT = 'variants/laravel-aiwmweb'
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
repo = Path(subprocess.check_output(['git', 'rev-parse', '--show-toplevel'], text=True).strip())
sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip()
args.output.mkdir(parents=True, exist_ok=True)
output = args.output.resolve()
with tempfile.TemporaryDirectory(prefix='aimw-cpanel-') as temporary:
    work = Path(temporary)
    archive = work/'source.zip'
    subprocess.run(['git', 'archive', '--format=zip', '-o', str(archive), sha, VARIANT], cwd=repo, check=True)
    with zipfile.ZipFile(archive) as source:
        source.extractall(work/'source')
    variant = work/'source'/VARIANT
    payload = work/'AIMWWeb'
    app = payload/'app'
    shutil.copytree(variant/'backend', app, ignore=shutil.ignore_patterns('.env', '.env.*', 'vendor', 'node_modules', 'tests', 'phpunit.xml', 'package-lock.json.bak', '.gitignore'))
    # Lock files, source and dependencies all come from the same recorded commit.
    subprocess.run([os.environ.get('COMPOSER_BIN', 'composer'), 'install', '--no-dev', '--classmap-authoritative', '--no-interaction', '--prefer-dist'], cwd=app, check=True)
    subprocess.run(['npm', 'ci', '--ignore-scripts'], cwd=app, check=True)
    subprocess.run(['npm', 'run', 'build'], cwd=app, check=True)
    shutil.rmtree(app/'node_modules')
    public = payload/'public'
    shutil.copytree(app/'public', public)
    (public/'index.php').write_text('''<?php
use Illuminate\\Http\\Request;
define('LARAVEL_START', microtime(true));
if (file_exists($maintenance = __DIR__.'/../app/storage/framework/maintenance.php')) { require $maintenance; }
require __DIR__.'/../app/vendor/autoload.php';
$app = require_once __DIR__.'/../app/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
''')
    shutil.copy(variant/'runtime/cpanel/install.php', public/'install.php')
    shutil.copy(variant/'runtime/cpanel/cron.sh', payload/'cron.sh')
    for language in ['AR', 'EN']:
        shutil.copy(variant/f'runtime/cpanel/README_{language}.md', payload/f'README_{language}.md')
    # Empty all mutable state and boot caches. No development or build secrets.
    for directory in [app/'storage', app/'bootstrap/cache']:
        if directory.exists():
            shutil.rmtree(directory)
        directory.mkdir(parents=True)
    for directory in ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']:
        (app/'storage'/directory).mkdir(parents=True, exist_ok=True)
    for path in [app/'.env', app/'public/hot', public/'hot']:
        path.unlink(missing_ok=True)
    (app/'.cpanel-profile').write_text('cpanel\n')
    (payload/'VERSION.txt').write_text(f'Laravel AIWMWeb cPanel candidate\nSource: {sha}\nPHP: >=8.3\n')
    files = {str(path.relative_to(payload)): hashlib.sha256(path.read_bytes()).hexdigest()
             for path in sorted(payload.rglob('*')) if path.is_file()}
    manifest = {'product': 'Laravel AIWMWeb', 'source_sha': sha, 'version': 'cpanel-'+sha[:12], 'php': '>=8.3',
                'profile': 'cpanel', 'release_decision': 'BLOCKED_PENDING_EXACT_SHA_ACCEPTANCE_AND_HOSTING_REHEARSAL',
                'ai_enabled': False, 'cron_enabled': False, 'files': files}
    (payload/'MANIFEST.json').write_text(json.dumps(manifest, indent=2)+'\n')
    destination = output/f'AIMWWeb-Laravel-cPanel-CANDIDATE-{sha[:12]}.zip'
    with zipfile.ZipFile(destination, 'w', zipfile.ZIP_DEFLATED) as bundle:
        for path in sorted(payload.rglob('*')):
            bundle.write(path, str(path.relative_to(work)))
    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix('.zip.sha256').write_text(f'{digest}  {destination.name}\n')
    print(json.dumps({'artifact': str(destination), 'source_sha': sha, 'sha256': digest, 'status': 'CANDIDATE_ONLY'}))
