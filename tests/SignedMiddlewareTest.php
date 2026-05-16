<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use TropikalAI\Connect\Domain\Security\SignedRequest;

final class SignedMiddlewareTest extends TestCase
{
    public function test_signed_middleware_rejects_missing_headers(): void
    {
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/schema";

        $this->get($path, ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_signed_middleware_rejects_expired_bad_signature_bad_query_and_wrong_installation(): void
    {
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/schema";

        $this->withHeaders($this->sign($installation, 'GET', $path, null, '', 'expired', time() - 1000))
            ->get($path, ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $headers = $this->sign($installation, 'GET', $path, null, '', 'bad_signature');
        $headers[SignedRequest::SIGNATURE_HEADER] = str_repeat('0', 64);
        $this->withHeaders($headers)->get($path, ['Accept' => 'application/json'])
            ->assertUnauthorized()
            ->assertExactJson(['error' => 'Invalid connect signature']);

        $this->withHeaders($this->sign($installation, 'GET', $path, 'a=1', '', 'bad_query'))
            ->get($path.'?b=2', ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $wrongPath = '/api/tropikal-connect/installations/cfi_wrong/schema';
        $this->withHeaders($this->sign($installation, 'GET', $wrongPath, null, '', 'wrong_installation'))
            ->get($wrongPath, ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_signed_middleware_rejects_bad_body_hash_and_replayed_nonce(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['create']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";

        $this->withHeaders($this->sign($installation, 'POST', $path, null, '{"title":"Different"}', 'bad_body'))
            ->json('POST', $path, ['title' => 'Actual'])
            ->assertUnauthorized();

        $schemaPath = "/api/tropikal-connect/installations/{$installation->public_id}/schema";
        $headers = $this->sign($installation, 'GET', $schemaPath, null, '', 'replay_nonce');

        $this->withHeaders($headers)->get($schemaPath, ['Accept' => 'application/json'])->assertOk();
        $this->withHeaders($headers)->get($schemaPath, ['Accept' => 'application/json'])->assertUnauthorized();
    }
}
