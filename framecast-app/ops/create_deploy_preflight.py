#!/usr/bin/env python3
"""Read-only, fail-closed check before a full container deployment.

Does not pause/resume admission, stop processes, migrate storage or invoke providers.
The bootstrap of the new controls requires the documented manual maintenance window.
"""
import argparse
import json
import posixpath
import subprocess
import sys


class Blocked(RuntimeError):
    pass


def run_json(command):
    try:
        result = subprocess.run(command, check=True, capture_output=True, text=True, timeout=30)
        return json.loads(result.stdout)
    except (OSError, subprocess.SubprocessError, ValueError) as exc:
        # Never include stdout/stderr: Docker inspection and PHP can contain secrets.
        raise Blocked('Deployment check unavailable or invalid; leave the running containers intact.') from exc


def journal_blockers(status):
    if not isinstance(status, dict):
        return ['invalid journal response']
    blockers = []
    for key in ('controls_enabled', 'draining', 'durable_planning'):
        if status.get(key) is not True:
            blockers.append(key)
    for group, states in [('planning', ('running', 'needs_attention')),
                          ('builds', ('running', 'cancel_requested', 'needs_attention'))]:
        counts = status.get(group)
        # Laravel serializes an empty PHP array as []. Only the empty list is valid.
        if counts == []:
            counts = {}
        if not isinstance(counts, dict) or any(type(v) is not int or v < 0 for v in counts.values()):
            blockers.append(group + ' inventory invalid')
            continue
        blockers.extend(group + '.' + state for state in states if counts.get(state, 0))
    for key in ('unconfirmed_workers', 'unresolved_attempts', 'pending_media'):
        if type(status.get(key)) is not int or status[key] != 0:
            blockers.append(key)
    return blockers


def persistent_storage(mounts, storage_path):
    if not isinstance(storage_path, str) or not storage_path.startswith('/') or storage_path == '/':
        return False
    if not isinstance(mounts, list):
        return False
    location = posixpath.normpath(storage_path)
    for mount in mounts:
        destination = mount.get('Destination', '')
        if (mount.get('Type') in ('bind', 'volume') and mount.get('RW') is True
                and isinstance(destination, str) and destination.startswith('/')):
            destination = posixpath.normpath(destination)
            if location == destination or location.startswith(destination.rstrip('/') + '/'):
                return True
    return False


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--compose', default='docker-compose.prod.yml')
    args = parser.parse_args()
    compose = ['docker', 'compose', '-f', args.compose]
    # Scope this check to the API service; never emit docker inspect's full environment.
    containers = run_json(compose + ['ps', '--format', 'json', 'api'])
    if isinstance(containers, dict):
        containers = [containers]
    if not isinstance(containers, list) or len(containers) != 1 or containers[0].get('State') != 'running':
        raise Blocked('Expected one running API container; inspect the host before deploying.')
    container_id = containers[0].get('ID', '')
    if not isinstance(container_id, str) or not container_id or any(c not in '0123456789abcdef' for c in container_id):
        raise Blocked('Invalid API container identity.')
    mounts = run_json(['docker', 'inspect', '--format', '{{json .Mounts}}', container_id])
    # This is intentionally required even with B2 enabled: legacy files must survive until
    # migration is verified, and scratch/cache cannot be mistaken for authoritative storage.
    storage_path = run_json(compose + ['exec', '-T', 'api', 'php', 'artisan', 'tinker', '--execute',
        "echo json_encode(config('filesystems.disks.local.root')); "])
    if not persistent_storage(mounts, storage_path):
        raise Blocked('API local storage is not on a persistent writable mount. Back up, migrate and verify it before replacing the container; do not mount an empty volume over existing files.')
    status = run_json(compose + ['exec', '-T', 'api', 'php', 'artisan', 'create:drain', 'status'])
    blockers = journal_blockers(status)
    if blockers:
        raise Blocked('Create deployment blocked: ' + ', '.join(blockers) + '. Pause/drain and reconcile first.')
    print(json.dumps({'preflight': 'passed', 'api_container': container_id,
        'note': 'Journal and mount checks only. Verify stopped worker/sandboxes and in-flight HTTP separately. Admission remains paused.'}))


if __name__ == '__main__':
    try:
        main()
    except Blocked as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
