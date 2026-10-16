<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Auth\RefreshTokenRotator;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Gesdinet\JWTRefreshTokenBundle\Security\Http\Authenticator\Token\PostRefreshTokenAuthenticationToken;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webmozart\Assert\Assert;

/**
 * Rotates the refresh token on POST /api/token/refresh through RefreshTokenRotator.
 *
 * Gesdinet's own listener ignores how many rows its delete removed, so two concurrent
 * refreshes of one token both get a new pair. This runs first, spends the token the
 * authenticator found (401 if a concurrent request already did), and stops Gesdinet's.
 */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: 16)]
final class RotateRefreshTokenOnRefreshListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
        private readonly ExtractorInterface $extractor,
        private readonly RefreshTokenRotator $refreshTokenRotator,
    ) {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof PostRefreshTokenAuthenticationToken) {
            return;
        }

        $request = Assert::notNull($this->requestStack->getCurrentRequest(), 'Refresh requires an HTTP request.');
        $presented = Assert::stringNotEmpty($this->extractor->getRefreshToken($request, 'refresh_token'));
        $user = Assert::isInstanceOf($event->getUser(), User::class);

        $tokens = $this->refreshTokenRotator->spend($token->getRefreshToken(), $presented, $user);

        $event->setData([...$event->getData(), ...$tokens]);
        $event->stopPropagation();
    }
}
