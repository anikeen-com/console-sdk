<?php

namespace Anikeen\Console\Tests;

use Anikeen\Console\Exceptions\AuthenticationException;
use Anikeen\Console\Exceptions\ClientNotAllowedException;
use Anikeen\Console\Facades\AnikeenConsole;
use Anikeen\Console\Tests\Fixtures\User;
use Anikeen\Id\Exceptions\RequestFreshAccessTokenException;
use Anikeen\Id\Exceptions\RequestRequiresAuthenticationException;
use GuzzleHttp\Psr7\Response;

class AuthenticationTest extends TestCase
{
    private function respondRefreshed(string $accessToken, string $refreshToken = 'refresh-2'): void
    {
        $this->idHttp->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'token_type' => 'Bearer', 'expires_in' => 1296000, 'access_token' => $accessToken, 'refresh_token' => $refreshToken,
        ])));
    }

    public function test_the_stored_token_is_sent(): void
    {
        $token = self::jwt();
        $user = $this->user($token);
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        $team = AnikeenConsole::forUser($user)->appTeam();

        $this->assertSame('team_1', $team->id);
        $this->assertSame('Bearer ' . $token, $this->request(0)->getHeaderLine('Authorization'));
        $this->assertCount(0, $this->idSent);
    }

    public function test_an_expired_token_is_refreshed_first_and_the_new_pair_saved(): void
    {
        $user = $this->user(self::jwt(-10, ['console-containers', 'console-servers:read']));
        $fresh = self::jwt(3600, jti: 'b');
        $this->respondRefreshed($fresh);
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        AnikeenConsole::forUser($user)->appTeam();

        $refresh = json_decode((string) $this->idSent[0]['request']->getBody(), true);
        $this->assertSame('/oauth/token', $this->idSent[0]['request']->getUri()->getPath());
        $this->assertSame('refresh_token', $refresh['grant_type']);
        $this->assertSame('refresh-1', $refresh['refresh_token']);
        $this->assertSame('console-containers console-servers:read', $refresh['scope']);

        $this->assertSame('Bearer ' . $fresh, $this->request(0)->getHeaderLine('Authorization'));
        $this->assertSame($fresh, $user->anikeen_id_access_token);
        $this->assertFalse($user->isDirty('anikeen_id_access_token'));
        $this->assertSame($fresh, User::find($user->id)->anikeen_id_access_token);
        $this->assertSame('refresh-2', User::find($user->id)->anikeen_id_refresh_token);
    }

    public function test_a_401_refreshes_and_retries_once(): void
    {
        $stale = self::jwt();
        $user = $this->user($stale);
        $fresh = self::jwt(jti: 'b');
        $this->respond(401, ['message' => 'Unauthenticated.']);
        $this->respondRefreshed($fresh);
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        $team = AnikeenConsole::forUser($user)->appTeam();

        $this->assertSame('Gleem', $team->name);
        $this->assertCount(2, $this->sent);
        $this->assertSame('Bearer ' . $stale, $this->request(0)->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ' . $fresh, $this->request(1)->getHeaderLine('Authorization'));
        $this->assertSame($fresh, User::find($user->id)->anikeen_id_access_token);
    }

    public function test_a_second_401_is_thrown(): void
    {
        $user = $this->user(self::jwt());
        $this->respond(401, ['message' => 'Unauthenticated.']);
        $this->respondRefreshed(self::jwt(jti: 'b'));
        $this->respond(401, ['message' => 'Unauthenticated.']);

        try {
            AnikeenConsole::forUser($user)->appTeam();
            $this->fail('No exception thrown.');
        } catch (AuthenticationException $exception) {
            $this->assertSame(401, $exception->status);
            $this->assertSame('Unauthenticated', $exception->getMessage());
        }

        $this->assertCount(2, $this->sent);
        $this->assertCount(1, $this->idSent);
    }

    public function test_a_refused_refresh_asks_for_a_new_login(): void
    {
        $user = $this->user(self::jwt(-10));
        $this->idHttp->append(new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant'])));

        try {
            AnikeenConsole::forUser($user)->appTeam();
            $this->fail('No exception thrown.');
        } catch (AuthenticationException $exception) {
            $this->assertInstanceOf(RequestFreshAccessTokenException::class, $exception->getPrevious());
        }

        $this->assertCount(0, $this->sent);
    }

    public function test_a_client_not_allowed_is_not_refreshed(): void
    {
        $user = $this->user(self::jwt());
        $this->respond(401, ['message' => 'This app may not use the Console API.', 'error' => 'client_not_allowed']);

        $this->expectException(ClientNotAllowedException::class);

        try {
            AnikeenConsole::forUser($user)->appTeam();
        } finally {
            $this->assertCount(0, $this->idSent);
        }
    }

    public function test_a_token_refreshed_meanwhile_is_taken_instead_of_refreshing_again(): void
    {
        $stale = self::jwt(-10);
        $user = $this->user($stale);
        $other = self::jwt(jti: 'other');
        User::whereKey($user->id)->update(['anikeen_id_access_token' => $other, 'anikeen_id_refresh_token' => 'refresh-other']);
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        AnikeenConsole::forUser($user)->appTeam();

        $this->assertCount(0, $this->idSent);
        $this->assertSame('Bearer ' . $other, $this->request(0)->getHeaderLine('Authorization'));
        $this->assertSame('refresh-other', $user->anikeen_id_refresh_token);
    }

    public function test_only_the_tokens_are_saved(): void
    {
        $user = $this->user(self::jwt(-10));
        $user->name = 'unsaved';
        $this->respondRefreshed(self::jwt(jti: 'b'));
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        AnikeenConsole::forUser($user)->appTeam();

        $this->assertSame('Ada', User::find($user->id)->name);
        $this->assertTrue($user->isDirty('name'));
    }

    public function test_a_fixed_token_is_never_refreshed(): void
    {
        $this->respond(401, ['message' => 'Unauthenticated.']);

        $this->expectException(AuthenticationException::class);

        try {
            AnikeenConsole::withToken('pat-token')->appTeam();
        } finally {
            $this->assertSame('Bearer pat-token', $this->request(0)->getHeaderLine('Authorization'));
            $this->assertCount(0, $this->idSent);
        }
    }

    public function test_a_user_without_tokens_cannot_call_the_api(): void
    {
        $this->expectException(RequestRequiresAuthenticationException::class);

        AnikeenConsole::forUser($this->user(null, null))->appTeam();
    }

    public function test_a_user_with_only_a_refresh_token_gets_a_new_access_token(): void
    {
        $user = $this->user(null);
        $fresh = self::jwt(jti: 'b');
        $this->respondRefreshed($fresh);
        $this->respond(200, ['data' => ['id' => 'team_1', 'name' => 'Gleem']]);

        AnikeenConsole::forUser($user)->appTeam();

        $this->assertSame('Bearer ' . $fresh, $this->request(0)->getHeaderLine('Authorization'));
    }

    public function test_the_shared_instance_keeps_no_token(): void
    {
        $this->expectException(RequestRequiresAuthenticationException::class);

        AnikeenConsole::forUser($this->user(self::jwt()));
        AnikeenConsole::appTeam();
    }
}
