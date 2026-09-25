<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ReleaseWorkflowTest extends TestCase
{
    public function test_aggregate_requires_every_applicable_exact_source_gate(): void
    {
        $root = dirname(__DIR__);
        $valid = ['QUALITY_RESULT' => 'success', 'INTENT_RESULT' => 'success', 'HAS_INTENTS' => 'true', 'PAYLOAD_RESULT' => 'success'];
        $cases = [[$valid, 0], [array_replace($valid, ['HAS_INTENTS' => 'false', 'PAYLOAD_RESULT' => 'skipped']), 0]];
        foreach (['QUALITY_RESULT', 'INTENT_RESULT', 'PAYLOAD_RESULT'] as $key) {
            foreach (['failure', 'cancelled', 'skipped', ''] as $result) {
                $cases[] = [array_replace($valid, [$key => $result]), 1];
            }
        }
        $cases[] = [array_replace($valid, ['HAS_INTENTS' => '']), 1];
        $cases[] = [array_replace($valid, ['HAS_INTENTS' => 'false']), 1];
        foreach ($cases as [$environment, $expected]) {
            $process = new Process(['bash', $root.'/scripts/check-release-ci.sh'], $root, $environment);
            $process->run();
            self::assertSame($expected, $process->getExitCode(), json_encode($environment, JSON_THROW_ON_ERROR));
        }
    }

    public function test_owner_workflows_bind_payload_tests_and_trusted_write_job(): void
    {
        $root = dirname(__DIR__);
        $ci = Yaml::parseFile($root.'/.github/workflows/ci.yml');
        $release = Yaml::parseFile($root.'/.github/workflows/release.yml');
        self::assertSame(['quality', 'release-intent', 'approved-payloads'], $ci['jobs']['ci']['needs']);
        self::assertSame('${{ matrix.source_sha }}', $ci['jobs']['approved-payloads']['with']['checkout-ref']);
        self::assertSame("needs.release-intent.outputs.has_intents == 'true'", $ci['jobs']['approved-payloads']['if']);
        self::assertSame('${{ needs.resolve.outputs.source_sha }}', $release['jobs']['quality']['with']['checkout-ref']);
        self::assertSame(['resolve', 'quality'], $release['jobs']['publish']['needs']);
        self::assertSame(false, $release['concurrency']['cancel-in-progress']);
        self::assertSame('read', $release['permissions']['contents']);
        self::assertSame('write', $release['jobs']['publish']['permissions']['contents']);
        self::assertStringContainsString("github.ref == 'refs/heads/main'", $release['jobs']['publish']['if']);
        self::assertStringContainsString("needs.quality.result == 'success'", $release['jobs']['publish']['if']);
        $steps = $release['jobs']['publish']['steps'];
        self::assertCount(3, $steps);
        self::assertSame('${{ github.sha }}', $steps[0]['with']['ref']);
        self::assertSame('control', $steps[0]['with']['path']);
        self::assertSame('${{ needs.resolve.outputs.source_sha }}', $steps[1]['with']['ref']);
        self::assertSame('source', $steps[1]['with']['path']);
        foreach ($steps as $step) {
            self::assertArrayNotHasKey('run', $step, 'The write job must not execute payload scripts.');
            self::assertMatchesRegularExpression('/^(actions\/checkout|tropikal-ai\/connect)@[0-9a-f]{40}$/', $step['uses']);
        }
        self::assertFalse($steps[0]['with']['persist-credentials']);
        self::assertFalse($steps[1]['with']['persist-credentials']);
        self::assertSame('publish', $steps[2]['with']['mode']);
        self::assertSame('${{ inputs.expected_control_sha }}', $steps[2]['with']['expected-control-sha']);
        self::assertSame('${{ needs.resolve.outputs.source_sha }}', $steps[2]['with']['verified-source-sha']);
        self::assertSame('${{ needs.resolve.result }}', $steps[2]['with']['resolve-result']);
        self::assertSame('${{ needs.quality.result }}', $steps[2]['with']['quality-result']);
        preg_match('/@([0-9a-f]{40})$/', $steps[2]['uses'], $pin);
        self::assertStringEndsWith('@'.$pin[1], $release['jobs']['quality']['uses']);
        self::assertStringEndsWith('@'.$pin[1], $ci['jobs']['quality']['uses']);
        self::assertStringEndsWith('@'.$pin[1], $ci['jobs']['approved-payloads']['uses']);
    }
}
