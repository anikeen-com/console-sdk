<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Exceptions\AccountConflictException;
use Anikeen\Console\Exceptions\ApiException;
use Anikeen\Console\Exceptions\AuthenticationException;
use Anikeen\Console\Exceptions\ClientNotAllowedException;
use Anikeen\Console\Exceptions\MissingScopeException;
use Anikeen\Console\Exceptions\NotFoundException;
use Anikeen\Console\Exceptions\RateLimitException;
use Anikeen\Console\Exceptions\ValidationFailedException;
use Anikeen\Id\Exceptions\ApiException as IdApiException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;

class ErrorsTest extends TestCase
{
    private function thrown(callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $exception) {
            return $exception;
        }

        $this->fail('No ApiException thrown.');
    }

    public static function errors(): array
    {
        return [
            'unauthenticated' => [401, ['message' => 'Unauthenticated.'], AuthenticationException::class],
            'client not allowed' => [401, ['message' => 'No.', 'error' => 'client_not_allowed'], ClientNotAllowedException::class],
            'missing scope' => [403, ['message' => 'The access token needs the scope "console-containers".', 'error' => 'missing_scope'], MissingScopeException::class],
            'other 403' => [403, ['message' => 'Only the owner may order.', 'error' => 'owner_only'], ApiException::class],
            'email not verified' => [403, ['message' => 'Verify your e-mail.', 'error' => 'email_not_verified'], ApiException::class],
            'not found' => [404, ['message' => 'Not found.'], NotFoundException::class],
            'account conflict' => [409, ['message' => 'Conflict.', 'error' => 'account_conflict'], AccountConflictException::class],
            'other conflict' => [409, ['message' => 'Protected.', 'error' => 'deletion_protected'], ApiException::class],
            'validation' => [422, ['message' => 'Invalid.', 'errors' => ['name' => ['Too long.']]], ValidationFailedException::class],
            'rate limit' => [429, ['message' => 'Too Many Attempts.', 'error' => 'rate_limited'], RateLimitException::class],
            'server error' => [502, ['message' => 'Checkout unavailable.', 'error' => 'checkout_unavailable'], ApiException::class],
        ];
    }

    #[DataProvider('errors')]
    public function test_errors_are_mapped(int $status, array $body, string $class): void
    {
        $this->respond($status, $body);

        $exception = $this->thrown(fn () => $this->console()->containers()->find('01JCT'));

        $this->assertSame($class, get_class($exception));
        $this->assertInstanceOf(IdApiException::class, $exception);
        $this->assertSame($status, $exception->status);
        $this->assertSame($body['error'] ?? null, $exception->error);
        $this->assertSame(rtrim($body['message'], '.'), $exception->getMessage());
    }

    public function test_a_missing_scope_names_the_scope(): void
    {
        $this->respond(403, ['message' => 'The access token needs the scope "console-containers".', 'error' => 'missing_scope']);
        $this->respond(403, ['message' => 'Missing scope.', 'error' => 'missing_scope', 'scope' => 'console-servers']);

        $this->assertSame('console-containers', $this->thrown(fn () => $this->console()->containers()->list())->scope());
        $this->assertSame('console-servers', $this->thrown(fn () => $this->console()->containers()->list())->scope());
    }

    public function test_a_rate_limit_says_when_to_retry(): void
    {
        $this->respond(429, ['message' => 'Too Many Attempts.', 'error' => 'rate_limited'], ['Retry-After' => '17']);

        $exception = $this->thrown(fn () => $this->console()->orders('team_1')->create([], ['tos_accepted' => true]));

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertSame(17, $exception->retryAfter);
        $this->assertTrue($exception->isTemporary());
    }

    public function test_validation_errors_per_field(): void
    {
        $this->respond(422, ['message' => 'The location is full.', 'errors' => ['items.0.attributes.location_id' => ['The location is full.']]]);

        $exception = $this->thrown(fn () => $this->console()->orders('team_1')->create([], ['tos_accepted' => true]));

        $this->assertSame(['items.0.attributes.location_id' => ['The location is full.']], $exception->errors());
        $this->assertSame('The location is full.', $exception->errorFor('items.0.attributes.location_id'));
        $this->assertNull($exception->errorFor('items.0.product_id'));
    }

    public function test_the_error_body_is_readable(): void
    {
        $this->respond(502, ['message' => 'Checkout unavailable.', 'error' => 'checkout_unavailable', 'order' => ['id' => '01JORD']]);

        $exception = $this->thrown(fn () => $this->console()->orders('team_1')->create([], ['tos_accepted' => true]));

        $this->assertSame('01JORD', $exception->body()->order->id);
        $this->assertTrue($exception->isTemporary());
    }

    public function test_an_unreachable_console(): void
    {
        $this->http->append(new ConnectException('Connection refused', new Request('GET', 'https://console.test/v1/app-team')));

        $exception = $this->thrown(fn () => $this->console()->appTeam());

        $this->assertSame(0, $exception->status);
        $this->assertStringContainsString('not reachable', $exception->getMessage());
        $this->assertTrue($exception->isTemporary());
    }
}
