<?php

namespace Tests\Unit;

use App\Services\AiService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: AiService's constructor always read
 * the generic ai.model/ai.base_url/ai.api_key config keys, never the
 * per-provider ai.providers.{provider}.* keys those same config values
 * actually live under. Setting AI_PROVIDER=anthropic and ANTHROPIC_API_KEY
 * was silently ignored -- every Anthropic call failed with "API key not
 * configured" regardless of what was in .env.
 */
class AiServiceProviderTest extends TestCase
{
    public function test_anthropic_provider_resolves_its_own_api_key_and_model_from_config(): void
    {
        config(['ai.provider' => 'anthropic']);
        config(['ai.providers.anthropic.api_key' => 'sk-ant-test-key']);
        config(['ai.providers.anthropic.model' => 'claude-3-5-sonnet-latest']);
        config(['ai.demo_mode' => false]);
        config(['ai.caching.enabled' => false]);

        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'Hello from Claude']],
                'model' => 'claude-3-5-sonnet-latest',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        $service = new AiService($client);
        $result = $service->generateResponse('Say hello');

        $this->assertTrue($result['success']);
        $this->assertSame('Hello from Claude', $result['response']);
        $this->assertSame('anthropic', $result['provider']);

        $this->assertCount(1, $history);
        $sentRequest = $history[0]['request'];
        $this->assertSame('api.anthropic.com', $sentRequest->getUri()->getHost());
        $this->assertSame('sk-ant-test-key', $sentRequest->getHeaderLine('x-api-key'));

        $body = json_decode((string) $sentRequest->getBody(), true);
        $this->assertSame('claude-3-5-sonnet-latest', $body['model']);
    }

    public function test_anthropic_provider_without_an_api_key_fails_cleanly_instead_of_calling_the_network(): void
    {
        config(['ai.provider' => 'anthropic']);
        config(['ai.providers.anthropic.api_key' => null]);
        config(['ai.demo_mode' => false]);

        // A mock handler with no queued responses throws if a request is
        // actually attempted -- proves the missing-key guard short-circuits.
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);

        $service = new AiService($client);
        $result = $service->generateResponse('Say hello');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API key not configured', $result['error']);
    }

    public function test_mock_provider_returns_a_deterministic_response_without_any_network_call(): void
    {
        config(['ai.provider' => 'mock']);
        config(['ai.demo_mode' => false]);

        $client = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);

        $service = new AiService($client);
        $result = $service->generateResponse('Give me a SUM formula');

        $this->assertTrue($result['success']);
        $this->assertSame('mock', $result['provider']);
        $this->assertStringContainsString('SUM', $result['response']);
    }
}
