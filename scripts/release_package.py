"""Publish a Filament package only from its verified owning workflow."""
import json
import os
import re
import subprocess

REPO = 'tropikal-ai/connect-filament'


def approve(env, head, dirty):
    expected = {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': REPO,
                'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main',
                'CI_RESULT': 'success', 'POLICY_RESULT': 'success'}
    sha, version = env.get('EXPECTED_SHA', ''), env.get('RELEASE_VERSION', '')
    if any(env.get(key) != value for key, value in expected.items()):
        raise ValueError('Release requires the owning main dispatch and passing CI/policy')
    if not re.fullmatch(r'[a-f0-9]{40}', sha) or head != sha or env.get('GITHUB_SHA') != sha or dirty:
        raise ValueError('Release source must be a clean checkout of the exact verified SHA')
    if not re.fullmatch(r'v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', version):
        raise ValueError('Release version must be canonical stable semver')
    return version, sha


def api(path, data=None, missing=False):
    args = ['gh', 'api', '--hostname', 'github.com', f'repos/{REPO}/{path}']
    if data is not None:
        args.extend(['--method', 'POST', '--input', '-'])
    response = subprocess.run(args, input=None if data is None else json.dumps(data),
                              text=True, capture_output=True, check=False)
    if response.returncode:
        if missing and '(HTTP 404)' in response.stderr:
            return None
        raise RuntimeError('Publication API failed; reconcile remote state before retrying')
    body = json.loads(response.stdout)
    if not isinstance(body, dict):
        raise ValueError('Invalid publication API response')
    return body


def tag_commit(version):
    tag = api(f'git/ref/tags/{version}', missing=True)
    if tag is None:
        return None
    target = tag.get('object', {})
    for _ in range(5):
        if not re.fullmatch(r'[a-f0-9]{40}', target.get('sha', '')):
            break
        if target.get('type') == 'commit':
            return target['sha']
        if target.get('type') != 'tag':
            break
        target = api(f"git/tags/{target['sha']}").get('object', {})
    raise ValueError('Release tag has no verified commit target')


def release(version, sha):
    if api('git/ref/heads/main').get('object', {}).get('sha') != sha:
        raise ValueError('Main advanced; run a fresh verified release')
    tag = tag_commit(version)
    if tag not in (None, sha):
        raise ValueError('An existing immutable tag conflicts with this source')
    existing = api(f'releases/tags/{version}', missing=True)
    if existing is None:
        if tag is None:
            api('git/refs', {'ref': f'refs/tags/{version}', 'sha': sha})
        if tag_commit(version) != sha or api('git/ref/heads/main').get('object', {}).get('sha') != sha:
            raise ValueError('Source changed before publication; preserve tag for reconciliation')
        api('releases', {'tag_name': version, 'target_commitish': sha, 'name': version,
                         'draft': False, 'prerelease': False,
                         'body': f'Verified Filament package from commit {sha}.'})
        existing = api(f'releases/tags/{version}')
    if (tag_commit(version) != sha or existing.get('tag_name') != version
            or existing.get('target_commitish') != sha or existing.get('draft') is not False
            or existing.get('prerelease') is not False or type(existing.get('id')) is not int
            or existing['id'] <= 0):
        raise ValueError('Published release readback does not match the verified source')
    return {'version': version, 'sha': sha, 'release_id': existing['id'],
            'platform_immutable': existing.get('immutable') is True}


if __name__ == '__main__':
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    changes = subprocess.check_output(['git', 'status', '--porcelain'], text=True).strip()
    print(json.dumps(release(*approve(os.environ, revision, changes)), sort_keys=True))
