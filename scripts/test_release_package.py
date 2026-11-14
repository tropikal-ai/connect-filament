import unittest
from pathlib import Path
from unittest.mock import patch

from release_package import approve, release


SHA = 'a' * 40
ENV = {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': 'tropikal-ai/connect-filament',
       'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main',
       'GITHUB_SHA': SHA, 'EXPECTED_SHA': SHA, 'RELEASE_VERSION': 'v0.2.22',
       'CI_RESULT': 'success', 'POLICY_RESULT': 'success'}


class ReleaseTest(unittest.TestCase):
    def test_context_rejects_unverified_sources_and_gates(self):
        self.assertEqual(approve(ENV, SHA, ''), ('v0.2.22', SHA))
        for key, value in [('GITHUB_ACTIONS', 'false'), ('GITHUB_REPOSITORY', 'other/repo'),
                           ('GITHUB_EVENT_NAME', 'push'), ('GITHUB_REF', 'refs/heads/feature'),
                           ('GITHUB_SHA', 'b' * 40), ('EXPECTED_SHA', 'main'),
                           ('RELEASE_VERSION', 'v0.2.22;false'), ('CI_RESULT', 'skipped'),
                           ('POLICY_RESULT', 'failure')]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                approve({**ENV, key: value}, SHA, '')
        for head, dirty in [('b' * 40, ''), (SHA, ' M composer.json')]:
            with self.assertRaises(ValueError):
                approve(ENV, head, dirty)

    def test_release_creates_verified_exact_source_once(self):
        state = {'tag': None, 'release': None}
        writes = []

        def api(path, data=None, missing=False):
            if path == 'git/ref/heads/main':
                return {'object': {'sha': SHA}}
            if path.startswith('git/ref/tags/'):
                return state['tag']
            if path.startswith('releases/tags/'):
                return state['release']
            writes.append(path)
            if path == 'git/refs':
                state['tag'] = {'object': {'type': 'commit', 'sha': data['sha']}}
            if path == 'releases':
                state['release'] = {'id': 10, **data}
            return {}

        with patch('release_package.api', side_effect=api):
            release('v0.2.22', SHA)
            release('v0.2.22', SHA)
        self.assertEqual(writes, ['git/refs', 'releases'])

    def test_moved_main_or_conflicting_tag_never_writes(self):
        for conflict in ['main', 'tag']:
            calls = []

            def api(path, data=None, missing=False):
                calls.append((path, data))
                if path == 'git/ref/heads/main':
                    return {'object': {'sha': 'b' * 40 if conflict == 'main' else SHA}}
                return {'object': {'type': 'commit', 'sha': 'b' * 40}}

            with self.subTest(conflict=conflict), patch('release_package.api', side_effect=api), self.assertRaises(ValueError):
                release('v0.2.22', SHA)
            self.assertFalse(any(data is not None for _, data in calls))

    def test_existing_release_mismatch_is_not_success(self):
        def api(path, data=None, missing=False):
            if path == 'git/ref/heads/main':
                return {'object': {'sha': SHA}}
            if path.startswith('git/ref/tags/'):
                return {'object': {'type': 'commit', 'sha': SHA}}
            return {'id': 1, 'tag_name': 'v0.2.22', 'target_commitish': 'main', 'draft': False, 'prerelease': False}

        with patch('release_package.api', side_effect=api), self.assertRaises(ValueError):
            release('v0.2.22', SHA)

    def test_uncertain_release_response_reconciles_without_new_writes(self):
        state = {'tag': None, 'release': None}
        writes = []

        def api(path, data=None, missing=False):
            if path == 'git/ref/heads/main':
                return {'object': {'sha': SHA}}
            if path.startswith('git/ref/tags/'):
                return state['tag']
            if path.startswith('releases/tags/'):
                return state['release']
            writes.append(path)
            if path == 'git/refs':
                state['tag'] = {'object': {'type': 'commit', 'sha': SHA}}
            else:
                state['release'] = {'id': 10, **data}
                raise RuntimeError('Uncertain response after publication')
            return {}

        with patch('release_package.api', side_effect=api):
            with self.assertRaises(RuntimeError):
                release('v0.2.22', SHA)
            release('v0.2.22', SHA)
        self.assertEqual(writes, ['git/refs', 'releases'])

    def test_owning_workflow_connects_readonly_gates_to_exact_source_publication(self):
        root = Path(__file__).resolve().parents[1]
        workflow = (root / '.github/workflows/release.yml').read_text()
        self.assertIn('workflow_dispatch:', workflow)
        self.assertIn('needs: [quality, policy]', workflow)
        self.assertIn("needs.quality.result == 'success' && needs.policy.result == 'success'", workflow)
        for field in ['EXPECTED_SHA: ${{ inputs.expected_sha }}', 'CI_RESULT: ${{ needs.quality.result }}',
                      'POLICY_RESULT: ${{ needs.policy.result }}', 'persist-credentials: false']:
            self.assertIn(field, workflow)
        self.assertEqual(workflow.count('contents: write'), 1)
        self.assertIn('needs: [quality, release-policy]', (root / '.github/workflows/ci.yml').read_text())


if __name__ == '__main__':
    unittest.main()
