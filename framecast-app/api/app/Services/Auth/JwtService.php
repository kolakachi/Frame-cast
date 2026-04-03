<?php

namespace App\Services\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Models\Workspace;
use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;

class JwtService
{
    public function issue(User $user, Workspace $workspace, AuthSession $session): string
    {
        $configuration = $this->configuration($workspace);
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval(sprintf('PT%dM', (int) config('auth_tokens.access_ttl_minutes'))));

        return $configuration->builder()
            ->issuedBy(config('app.url'))
            ->permittedFor(config('app.url'))
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->relatedTo((string) $user->getKey())
            ->withClaim('user_id', $user->getKey())
            ->withClaim('workspace_id', $workspace->getKey())
            ->withClaim('session_id', $session->getKey())
            ->withClaim('role', $user->role)
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /**
     * @return array{user_id:int,workspace_id:int,session_id:int,role:string|null}
     */
    public function parse(string $token): array
    {
        $parser = new Parser(new JoseEncoder());
        $parsed = $parser->parse($token);

        $workspaceId = (int) $parsed->claims()->get('workspace_id');
        $secret = $this->workspaceSecret($workspaceId);
        $configuration = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));

        $constraints = [
            new SignedWith($configuration->signer(), $configuration->verificationKey()),
            new LooseValidAt(new NativeClock('UTC')),
        ];

        if (! $configuration->validator()->validate($parsed, ...$constraints)) {
            throw new RuntimeException('JWT validation failed.');
        }

        return [
            'user_id' => (int) $parsed->claims()->get('user_id'),
            'workspace_id' => $workspaceId,
            'session_id' => (int) $parsed->claims()->get('session_id'),
            'role' => $parsed->claims()->get('role'),
        ];
    }

    private function configuration(Workspace $workspace): Configuration
    {
        return Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->workspaceSecret($workspace->getKey())),
        );
    }

    private function workspaceSecret(int $workspaceId): string
    {
        $rootSecret = (string) config('auth_tokens.jwt_secret');

        if ($rootSecret === '') {
            throw new RuntimeException('JWT_SECRET is not configured.');
        }

        return hash_hmac('sha256', (string) $workspaceId, $rootSecret);
    }
}
