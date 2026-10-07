#!/usr/bin/env python3
"""Activate an already staged worker release. Admission stays paused on success or failure.
Requires the bootstrap service layout documented in create-release-runbook.md.
"""
import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import uuid


class Blocked(RuntimeError):
    pass


def command(args, timeout=30):
    try:
        return subprocess.run(args, check=True, capture_output=True, text=True, timeout=timeout).stdout.strip()
    except (OSError, subprocess.SubprocessError) as exc:
        raise Blocked('Release command failed; admission must remain paused. Inspect the service and deployment log.') from exc


def stop_and_wait(run, *, timeout, clock=time.monotonic, sleep=time.sleep):
    run(['sudo', 'systemctl', 'stop', '--no-block', 'wyv-create-worker'])
    deadline = clock() + timeout
    while True:
        state = json_state(run)
        if state.get('ActiveState') == 'inactive' and state.get('MainPID') == '0':
            return
        if state.get('ActiveState') == 'failed':
            raise Blocked('Worker failed while stopping; inspect recovery journals before activation.')
        if clock() >= deadline:
            raise Blocked('Drain timed out. Worker remains draining; no kill, replacement, or resume was attempted.')
        sleep(min(2, max(0, deadline - clock())))


def json_state(run):
    output = run(['systemctl', 'show', 'wyv-create-worker', '-p', 'ActiveState', '-p', 'MainPID'])
    return dict(line.split('=', 1) for line in output.splitlines() if '=' in line)


def no_sandboxes(run):
    for filters in [['--filter', 'name=wyv-create-'], ['--filter', 'label=com.docker.compose.project=wyv-hyperframes-proof']]:
        if run(['docker', 'ps', '-q'] + filters):
            raise Blocked('A Create sandbox is still running; preserve it and inspect the worker journal.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('revision', help='Staged release commit (40 lowercase hex characters)')
    parser.add_argument('--root', default='/opt/wyv-create')
    parser.add_argument('--timeout', type=int, default=600)
    args = parser.parse_args()
    if not re.fullmatch('[a-f0-9]{40}', args.revision) or not 0 <= args.timeout <= 1800:
        raise Blocked('Require a full commit and a timeout of 0-1800 seconds.')
    root = Path(args.root).resolve()
    candidate = root / 'releases' / args.revision / 'hyperframes-worker'
    if candidate.resolve() != candidate or not candidate.is_dir():
        raise Blocked('Stage a real immutable release directory before activation.')
    current = root / 'current'
    if not current.is_symlink():
        raise Blocked('Bootstrap the current symlink and systemd service in a manual maintenance window first.')
    previous = current.resolve(strict=True)
    # Serialize activation and rollback on the worker host. This does not replace API drain.
    with open(root / '.release.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise Blocked('Another worker release operation is in progress.') from exc
        unit = command(['systemctl', 'show', 'wyv-create-worker', '-p', 'KillMode', '-p', 'TimeoutStopUSec', '-p', 'ExecStart'])
        if 'KillMode=mixed' not in unit.splitlines() or 'TimeoutStopUSec=infinity' not in unit.splitlines() or str(current / 'agent/app-worker.mjs') not in unit:
            raise Blocked('Installed service does not have the required release path and drain-safe stop policy.')
        manifest = json.loads((candidate / 'RELEASE.json').read_text())
        if manifest.get('revision') != args.revision:
            raise Blocked('Candidate directory and manifest revisions differ.')
        command(['node', str(candidate / 'scripts/release.mjs'), 'verify'], timeout=180)
        preflight = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', 'framecast-prod',
                     'cd /opt/framecast/app/framecast-app && python3 ops/create_deploy_preflight.py']
        command(preflight, timeout=180)
        stop_and_wait(command, timeout=args.timeout)
        no_sandboxes(command)
        # Recheck after drain; never activate against newly admitted or unresolved work.
        command(preflight, timeout=180)
        record = root / ('release-attempt-' + str(uuid.uuid4()) + '.json')
        record.write_text(json.dumps({'previous': str(previous), 'candidate': str(candidate),
                                     'revision': args.revision, 'admission': 'paused', 'state': 'prepared'}, indent=2))
        temporary = root / ('.current-' + str(uuid.uuid4()))
        os.symlink(candidate, temporary)
        os.replace(temporary, current)
        # No automatic rollback: a failed startup needs inspection, and the same verified procedure
        # can select the retained previous release without deleting journals or buying work again.
        command(['sudo', 'systemctl', 'start', 'wyv-create-worker'])
        record.write_text(json.dumps({'previous': str(previous), 'candidate': str(candidate),
                                     'revision': args.revision, 'admission': 'paused', 'state': 'started_needs_verification'}, indent=2))
        print(json.dumps({'activated': args.revision, 'admission': 'paused', 'record': str(record),
                          'next': 'Verify release_verified startup event, service health, API compatibility, asset reads and scheduler before resuming.'}))


if __name__ == '__main__':
    try:
        main()
    except (Blocked, OSError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
