<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LoginTimingEqualizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Webmozart\Assert\Assert;

/**
 * Email + password login for GraphQL, throttled like the REST login firewall.
 */
final class LoginService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenIssuer $tokenIssuer,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'app.login_rate_limiter')]
        private readonly DefaultLoginRateLimiter $loginRateLimiter,
        private readonly LoginTimingEqualizer $timingEqualizer,
    ) {
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    public function login(string $email, string $password): array
    {
        $request = Assert::notNull(
            $this->requestStack->getCurrentRequest(),
            'Login requires an HTTP request.',
        );

        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        // Peek, same as the REST login firewall: a successful login must not spend
        // the shared IP budget. consume() counts against that budget, and reset()
        // only clears the username+IP limiter.
        $limit = $this->loginRateLimiter->peek($request);
        if (!$limit->isAccepted() || 0 === $limit->getRemainingTokens()) {
            throw new UnauthorizedHttpException('Bearer', 'Too many failed login attempts, please try again later.');
        }

        try {
            $user = $this->authenticate($email, $password);
        } catch (UnauthorizedHttpException $e) {
            $this->loginRateLimiter->consume($request);

            throw $e;
        }

        $this->loginRateLimiter->reset($request);

        return $this->tokenIssuer->issue($user);
    }

    private function authenticate(string $email, string $password): User
    {
        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            // Same cost as a wrong password, so timing doesn't reveal unknown emails.
            $this->timingEqualizer->spendPasswordCheck($password);

            throw new UnauthorizedHttpException('Bearer', 'Invalid credentials.');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid credentials.');
        }

        $this->tokenIssuer->assertCanAuthenticate($user);

        return $user;
    }
}
