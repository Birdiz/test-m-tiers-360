<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * One shared token for the ops team's API, until there is real identity (SSO, per-user roles).
 */
final readonly class OpsTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        #[Autowire(env: 'OPS_API_TOKEN')]
        private string $opsToken,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if ('' === $this->opsToken || !hash_equals($this->opsToken, $accessToken)) {
            throw new BadCredentialsException('Invalid token.');
        }

        return new UserBadge('ops');
    }
}
