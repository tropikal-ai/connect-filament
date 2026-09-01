<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\Models\Installation;

class PublicEmbedTest extends TestCase
{
    public function test_complete_public_chat_route_surface_uses_api_middleware(): void
    {
        $routes = [
            'connect-filament.embed.chat.info' => ['GET'],
            'connect-filament.embed.chat' => ['POST'],
            'connect-filament.embed.chat.session' => ['GET'],
            'connect-filament.embed.chat.history.list' => ['GET'],
            'connect-filament.embed.chat.history.read' => ['GET'],
            'connect-filament.embed.chat.history.delete' => ['DELETE'],
            'connect-filament.embed.chat.history.clear' => ['DELETE'],
            'connect-filament.embed.human-verification.challenge' => ['POST'],
            'connect-filament.embed.chat.actions.confirm' => ['POST'],
            'connect-filament.embed.chat.actions.cancel' => ['POST'],
        ];

        foreach ($routes as $name => $methods) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame($methods, array_values(array_diff($route->methods(), ['HEAD'])));
            $this->assertContains('api', $route->gatherMiddleware());
            $this->assertNotContains('web', $route->gatherMiddleware());
        }
    }

    public function test_session_preserves_canonical_query_in_url_and_signature(): void
    {
        $installation = $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['session_id' => 'session-1', 'messages' => []])]);

        $this->getJson('/tropikal-connect/api/chat/session?resume_token=z%2Fz&a=1', [
            'X-Embed-Origin' => 'https://cms.example.com',
        ])
            ->assertOk()
            ->assertJsonPath('session_id', 'session-1');

        Http::assertSent(function (ClientRequest $request) use ($installation): bool {
            $query = 'a=1&resume_token=z%2Fz';
            $expected = SignedRequest::headersWithRequestOrigin(
                (string) $installation->server_signing_key_encrypted,
                (string) $installation->public_id,
                'GET',
                '/api/connect-filament/embed/session',
                'https://cms.example.com',
                $query,
                '',
                (int) $request->header(SignedRequest::TIMESTAMP_HEADER)[0],
                (string) $request->header(SignedRequest::NONCE_HEADER)[0],
            );

            return $request->url() === 'https://control.example.com/api/connect-filament/embed/session?'.$query
                && hash_equals($expected[SignedRequest::SIGNATURE_HEADER], $request->header(SignedRequest::SIGNATURE_HEADER)[0]);
        });
    }

    public function test_public_embed_signature_binds_the_normalized_visitor_origin(): void
    {
        $installation = $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['display_name' => 'Example Front Desk'])]);

        $this->getJson('/tropikal-connect/api/chat/info?b=2&a=1', [
            'X-Embed-Origin' => 'https://cms.example.com',
        ])->assertOk();

        Http::assertSent(function (ClientRequest $request) use ($installation): bool {
            $timestamp = (int) $request->header(SignedRequest::TIMESTAMP_HEADER)[0];
            $nonce = (string) $request->header(SignedRequest::NONCE_HEADER)[0];
            $bodyHash = SignedRequest::bodyHash('');
            $canonical = SignedRequest::canonical(
                (string) $installation->public_id,
                'GET',
                '/api/connect-filament/embed/info',
                'a=1&b=2',
                $timestamp,
                $nonce,
                $bodyHash,
            )."\nhttps://cms.example.com";
            $expected = hash_hmac(
                'sha256',
                $canonical,
                (string) $installation->server_signing_key_encrypted,
            );

            return $request->hasHeader(SignedRequest::REQUEST_ORIGIN_HEADER, 'https://cms.example.com')
                && hash_equals($expected, $request->header(SignedRequest::SIGNATURE_HEADER)[0]);
        });
    }

    public function test_history_cookie_is_http_only_secure_host_scoped_and_sliding(): void
    {
        config()->set('session.domain', '.cms.example.com');
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(function (ClientRequest $request) {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $payload['visitor_history_token']);

            return Http::response(['items' => [], 'history_capability' => 'write-only-capability']);
        });

        $response = $this->getJson('/tropikal-connect/api/chat/history')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(
            fn ($candidate) => $candidate->getName() === '__Host-tropikal-chat-history'
        );

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertGreaterThan(now()->addDays(29)->getTimestamp(), $cookie->getExpiresTime());
        $this->assertJsonStringNotEqualsJsonString(json_encode(['visitor_history_token' => $cookie->getValue()]), $response->getContent());
    }

    public function test_malformed_history_cookie_is_rotated_before_proxying(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $sent = null;
        Http::fake(function (ClientRequest $request) use (&$sent) {
            $sent = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR)['visitor_history_token'];

            return Http::response(['items' => [], 'history_capability' => 'write-only-capability']);
        });

        $response = $this->withCredentials()->withUnencryptedCookie('__Host-tropikal-chat-history', 'malformed')
            ->getJson('/tropikal-connect/api/chat/history')
            ->assertOk();

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $sent);
        $this->assertNotSame('malformed', $sent);
        $this->assertNotEmpty($response->headers->getCookies());
    }

    public function test_local_http_uses_non_host_cookie_name_without_secure_flag(): void
    {
        URL::forceScheme('http');
        URL::forceRootUrl('http://localhost');
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['items' => [], 'history_capability' => 'write-only-capability'])]);

        $response = $this->getJson('http://localhost/tropikal-connect/api/chat/history')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(
            fn ($candidate) => $candidate->getName() === 'tropikal-chat-history'
        );

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
        $this->assertNotSame('__Host-tropikal-chat-history', $cookie->getName());
    }

    public function test_history_routes_sign_only_cookie_derived_json_and_conversation_reference(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $token = str_repeat('a', 64);
        $conversation = '018f1e22-9abc-7def-8123-456789abcdef';
        Http::fake(['*' => Http::response(['messages' => []])]);

        $this->withCredentials()->withUnencryptedCookie('__Host-tropikal-chat-history', $token)
            ->getJson('/tropikal-connect/api/chat/history/'.$conversation)
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) use ($token, $conversation): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://control.example.com/api/connect-filament/embed/history/read', $request->url());
            $this->assertSame(['visitor_history_token' => $token, 'conversation_ref' => $conversation], $payload);
            $this->assertTrue($request->hasHeader(SignedRequest::BODY_HASH_HEADER, hash('sha256', $request->body())));
            $this->assertStringNotContainsString($token, $request->url());

            return true;
        });
    }

    public function test_history_mutations_require_intent_and_same_origin(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response('', 204)]);
        $conversation = '018f1e22-9abc-7def-8123-456789abcdef';

        $this->deleteJson('/tropikal-connect/api/chat/history/'.$conversation)->assertStatus(428);
        $this->withHeaders(['X-Tropikal-History-Intent' => '1', 'Origin' => 'https://evil.example'])
            ->deleteJson('/tropikal-connect/api/chat/history/'.$conversation)
            ->assertForbidden();
        Http::assertNothingSent();

        $this->withHeaders(['X-Tropikal-History-Intent' => '1', 'Origin' => 'https://cms.example.com'])
            ->deleteJson('/tropikal-connect/api/chat/history/'.$conversation)
            ->assertNoContent();
        $this->withHeaders(['X-Tropikal-History-Intent' => '1', 'Origin' => 'https://cms.example.com'])
            ->deleteJson('/tropikal-connect/api/chat/history/clear')
            ->assertNoContent();
        Http::assertSentCount(2);
    }

    public function test_action_routes_proxy_exact_action_without_history_cookie(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['status' => 'cancelled'])]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';

        $this->withCredentials()->withUnencryptedCookie('__Host-tropikal-chat-history', str_repeat('b', 64))
            ->postJson('/tropikal-connect/api/chat/actions/'.$action.'/cancel', ['decision_capability' => 'opaque'])
            ->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url()
            === 'https://control.example.com/api/connect-filament/embed/actions/'.$action.'/cancel'
            && ! str_contains($request->body(), str_repeat('b', 64)));
    }

    public function test_human_verification_challenge_uses_the_exact_signed_public_contract(): void
    {
        $installation = $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';
        $expectedBody = json_encode([
            'session_id' => 'embed_session_123',
            'action_id' => $action,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Http::fake(['*' => Http::response([
            'schema' => 'connect.public.human_verification_challenge',
            'contract_version' => '1.0',
            '_tropikal_connect' => true,
            'data' => ['challenge' => ['seed' => 'seed-1', 'bits' => 18]],
        ])]);

        $this->postJson('/tropikal-connect/api/human-verification/challenge', [
            'session_id' => 'embed_session_123',
            'action_id' => $action,
        ], ['X-Embed-Origin' => 'https://cms.example.com'])
            ->assertOk()
            ->assertJsonPath('challenge.seed', 'seed-1');

        Http::assertSent(function (ClientRequest $request) use ($installation, $expectedBody): bool {
            $path = '/api/connect-filament/public/human-verification/challenge';
            $expected = SignedRequest::headersWithRequestOrigin(
                (string) $installation->server_signing_key_encrypted,
                (string) $installation->public_id,
                'POST',
                $path,
                'https://cms.example.com',
                '',
                $expectedBody,
                (int) $request->header(SignedRequest::TIMESTAMP_HEADER)[0],
                (string) $request->header(SignedRequest::NONCE_HEADER)[0],
            );

            return $request->url() === 'https://control.example.com'.$path
                && $request->body() === $expectedBody
                && hash_equals($expected[SignedRequest::SIGNATURE_HEADER], $request->header(SignedRequest::SIGNATURE_HEADER)[0]);
        });
    }

    public function test_confirm_verifies_bound_proof_then_forwards_only_the_decision_contract(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';
        $requests = [];
        Http::fake(function (ClientRequest $request) use (&$requests) {
            $requests[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR)];

            return str_ends_with($request->url(), '/public/human-verification')
                ? Http::response(['verified' => true])
                : Http::response(['status' => 'executed', 'reply_kind' => 'booking_confirmed']);
        });

        $this->postJson('/tropikal-connect/api/chat/actions/'.$action.'/confirm', [
            'decision_capability' => 'opaque-decision-capability',
            'resume_token' => 'opaque-resume-token',
            'session_id' => 'embed_session_123',
            'proof_of_work_token' => 'browser-proof-token',
            'human_verified' => true,
        ])->assertOk()->assertJsonPath('status', 'executed');

        $this->assertSame([
            [
                'url' => 'https://control.example.com/api/connect-filament/public/human-verification',
                'body' => [
                    'token' => 'browser-proof-token',
                    'session_id' => 'embed_session_123',
                    'action_id' => $action,
                ],
            ],
            [
                'url' => 'https://control.example.com/api/connect-filament/embed/actions/'.$action.'/confirm',
                'body' => [
                    'decision_capability' => 'opaque-decision-capability',
                    'resume_token' => 'opaque-resume-token',
                    'session_id' => 'embed_session_123',
                ],
            ],
        ], $requests);
    }

    public function test_failed_or_missing_proof_never_reaches_action_execution(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';
        Http::fake(['*' => Http::response(['verified' => false], 422)]);

        $this->postJson('/tropikal-connect/api/chat/actions/'.$action.'/confirm', [
            'decision_capability' => 'opaque-decision-capability',
            'resume_token' => 'opaque-resume-token',
            'session_id' => 'embed_session_123',
            'proof_of_work_token' => 'bad-proof',
        ])->assertStatus(422)->assertExactJson([
            'error' => 'verification_failed',
            'message' => 'Please complete human verification again.',
        ]);
        Http::assertSentCount(1);

        Http::fake();
        $this->postJson('/tropikal-connect/api/chat/actions/'.$action.'/confirm', [
            'decision_capability' => 'opaque-decision-capability',
            'resume_token' => 'opaque-resume-token',
            'session_id' => 'embed_session_123',
        ])->assertStatus(422)->assertJsonPath('error', 'verification_failed');
        Http::assertNothingSent();
    }

    public function test_verification_outage_fails_closed_with_a_safe_typed_error(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['detail' => 'redis password leaked'], 503)]);

        $response = $this->postJson(
            '/tropikal-connect/api/chat/actions/018f1e22-9abc-7def-8123-456789abcdef/confirm',
            [
                'decision_capability' => 'opaque-decision-capability',
                'resume_token' => 'opaque-resume-token',
                'session_id' => 'embed_session_123',
                'proof_of_work_token' => 'browser-proof-token',
            ],
        )->assertStatus(503)->assertExactJson([
            'error' => 'verification_unavailable',
            'message' => 'Human verification is temporarily unavailable. Please retry.',
        ]);
        $this->assertStringNotContainsString('redis', $response->getContent());
        Http::assertSentCount(1);
    }

    public function test_cancel_remains_proof_free_and_idempotent(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';
        Http::fake(['*' => Http::response(['status' => 'canceled', 'reply_kind' => 'booking_canceled'])]);
        $payload = [
            'decision_capability' => 'opaque-decision-capability',
            'resume_token' => 'opaque-resume-token',
            'session_id' => 'embed_session_123',
        ];

        $this->postJson('/tropikal-connect/api/chat/actions/'.$action.'/cancel', $payload)->assertOk();
        $this->postJson('/tropikal-connect/api/chat/actions/'.$action.'/cancel', $payload)->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/actions/'.$action.'/cancel')
            && ! str_contains($request->url(), 'human-verification'));
    }

    public function test_chat_relays_typed_action_review_field_keys(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'reply' => 'Please review your booking.',
            'pending_action' => [
                'review' => [
                    'schema' => 'interactive_action_review.v1',
                    'id' => '018f1e22-9abc-7def-8123-456789abcdef',
                    'kind' => 'booking.appointment.create',
                    'fields' => [
                        ['key' => 'service', 'label' => 'Service', 'value' => 'Consultation'],
                        ['key' => 'email', 'label' => 'Email', 'value' => 'a***@example.test'],
                    ],
                ],
                'decision_capability' => 'opaque-decision-capability',
            ],
            'resume_token' => 'opaque-resume-token',
        ])]);

        $this->postJson('/tropikal-connect/api/chat', [
            'message' => 'Book the selected time',
            'session_id' => 'embed_session_123',
        ])->assertOk()
            ->assertJsonPath('pending_action.review.fields.0.key', 'service')
            ->assertJsonPath('pending_action.review.fields.1.key', 'email')
            ->assertJsonPath('pending_action.decision_capability', 'opaque-decision-capability');
    }

    public function test_action_review_rejects_secret_shaped_field_identifiers(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        foreach (['api_key', 'password', 'server_secret'] as $fieldKey) {
            Http::fake(['*' => Http::response([
                'pending_action' => [
                    'review' => [
                        'schema' => 'interactive_action_review.v1',
                        'fields' => [[
                            'key' => $fieldKey,
                            'label' => 'Sensitive',
                            'value' => 'must-not-leak',
                        ]],
                    ],
                ],
            ])]);

            $response = $this->postJson('/tropikal-connect/api/chat', [
                'message' => 'Book the selected time',
                'session_id' => 'embed_session_123',
            ])->assertStatus(502);

            $this->assertStringNotContainsString('must-not-leak', $response->getContent());
            $response->assertJsonPath('error', 'chat_unavailable');
        }
    }

    public function test_action_review_field_key_exception_requires_the_versioned_schema(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response([
            'pending_action' => [
                'review' => [
                    'schema' => 'unknown_review.v1',
                    'fields' => [[
                        'key' => 'service',
                        'label' => 'Service',
                        'value' => 'Consultation',
                    ]],
                ],
            ],
        ])]);

        $this->postJson('/tropikal-connect/api/chat', [
            'message' => 'Book the selected time',
            'session_id' => 'embed_session_123',
        ])->assertStatus(502)
            ->assertJsonPath('error', 'chat_unavailable');
    }

    public function test_stable_and_hashed_assets_preserve_only_safe_provider_headers(): void
    {
        Http::fake([
            'https://control.example.com/embed/chat-widget.js' => Http::response('loader', 200, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
                'ETag' => '"loader-1"',
                'Set-Cookie' => 'provider-secret=bad',
            ]),
            'https://control.example.com/embed/iframe.html' => Http::response('<!doctype html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-cache, max-age=0, must-revalidate',
                'Last-Modified' => 'Sat, 29 Aug 2026 00:00:00 GMT',
                'Content-Security-Policy' => "default-src 'none'",
            ]),
            'https://control.example.com/embed/assets/iframe-a1b2c3d4.js' => Http::response('iframe', 200, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'ETag' => '"iframe-a1b2c3d4"',
            ]),
        ]);

        $stable = $this->get('/tropikal-connect/embed/chat-widget.js')
            ->assertOk()
            ->assertHeader('ETag', '"loader-1"');
        $this->assertStringNotContainsString('provider-secret', (string) $stable->headers->get('Set-Cookie'));
        $this->assertStringContainsString('no-cache', (string) $stable->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $stable->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $stable->headers->get('Cache-Control'));
        $hashed = $this->get('/tropikal-connect/embed/assets/iframe-a1b2c3d4.js')
            ->assertOk()
            ->assertHeader('ETag', '"iframe-a1b2c3d4"');
        $this->assertStringContainsString('public', (string) $hashed->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=31536000', (string) $hashed->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $hashed->headers->get('Cache-Control'));
        $this->get('/tropikal-connect/embed/iframe.html')
            ->assertOk()
            ->assertHeader('Last-Modified', 'Sat, 29 Aug 2026 00:00:00 GMT')
            ->assertHeader('Content-Security-Policy', "default-src 'none'");

        Http::assertSent(fn (ClientRequest $request): bool => ! $request->hasHeader('Cookie')
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader(SignedRequest::SIGNATURE_HEADER));
    }

    public function test_iframe_document_loads_content_hashed_assets_directly_from_the_control_plane(): void
    {
        Http::fake([
            'https://control.example.com/embed/iframe.html' => Http::response(
                '<script type="module" src="./assets/iframe-a1b2c3d4.js"></script>'
                .'<link rel="stylesheet" href="./assets/iframe-e5f6g7h8.css">',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $response = $this->get('/tropikal-connect/embed/iframe.html')->assertOk();

        $response->assertSee('https://control.example.com/embed/assets/iframe-a1b2c3d4.js', false)
            ->assertSee('https://control.example.com/embed/assets/iframe-e5f6g7h8.css', false)
            ->assertDontSee('./assets/', false);
    }

    public function test_asset_proxy_rejects_flat_mutable_and_unsafe_paths(): void
    {
        Http::fake(['*' => Http::response('must-not-be-used')]);

        foreach ([
            '/tropikal-connect/embed/iframe.js',
            '/tropikal-connect/embed/assets/plain.js',
            '/tropikal-connect/embed/assets/../secrets.js',
            '/tropikal-connect/embed/assets/iframe-a1b2c3d4.php',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }

        Http::assertNothingSent();
    }

    public function test_upstream_five_hundred_response_is_publicly_redacted(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['detail' => 'SQL password=server-secret'], 500)]);

        $response = $this->getJson('/tropikal-connect/api/chat/info')->assertStatus(502);
        $this->assertStringNotContainsString('server-secret', $response->getContent());
        $response->assertExactJson([
            'error' => 'chat_unavailable',
            'message' => 'Website chat is temporarily unavailable.',
        ]);
    }

    public function test_secret_shaped_success_response_is_rejected_without_disclosure(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['items' => [], 'server_secret' => 'must-not-leak'])]);

        $response = $this->getJson('/tropikal-connect/api/chat/history')->assertStatus(502);
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $response->assertJsonPath('error', 'chat_unavailable');
    }
}
