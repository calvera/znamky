<?php

declare(strict_types=1);

namespace App\Security;

use Gesdinet\JWTRefreshTokenBundle\Model\FamilyRefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\HashedRefreshTokenManager;
use Gesdinet\JWTRefreshTokenBundle\Model\ListRefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Presenting a stored sha256$ digest must not authenticate.
 *
 * HashedRefreshTokenManager::get() looks the token up by its digest, then — while
 * accept_stored_in_the_clear is on — looks the presented string up raw. That second
 * lookup is what keeps pre-hashing rows working, and it also accepts the digest
 * already stored in the table. A database copy would otherwise be enough to refresh.
 * Client secrets are hex from random_bytes() and never carry this prefix.
 *
 * Decorates the hashed manager itself so this check runs before that fallback,
 * and so a lookup of sha256$… by the hashed manager still reaches the database.
 */
#[AsDecorator(decorates: 'gesdinet_jwt_refresh_token.hashed_refresh_token_manager')]
final class RejectStoredDigestRefreshTokenManager implements FamilyRefreshTokenManagerInterface, ListRefreshTokenManagerInterface, RefreshTokenManagerInterface, RevokeRefreshTokenManagerInterface
{
    private const string STORED_DIGEST_PREFIX = 'sha256$';

    public function __construct(
        #[AutowireDecorated]
        private readonly HashedRefreshTokenManager $inner,
    ) {
    }

    public function get(string $refreshToken): ?RefreshTokenInterface
    {
        if (str_starts_with($refreshToken, self::STORED_DIGEST_PREFIX)) {
            return null;
        }

        return $this->inner->get($refreshToken);
    }

    public function getLastFromUsername(string $username): ?RefreshTokenInterface
    {
        return $this->inner->getLastFromUsername($username);
    }

    public function save(RefreshTokenInterface $refreshToken): void
    {
        $this->inner->save($refreshToken);
    }

    public function delete(RefreshTokenInterface $refreshToken, bool $andFlush = true): int
    {
        return $this->inner->delete($refreshToken, $andFlush);
    }

    public function revokeAllInvalid(?\DateTimeInterface $datetime = null, bool $andFlush = true): array
    {
        return $this->inner->revokeAllInvalid($datetime, $andFlush);
    }

    public function revokeAllInvalidBatch(?\DateTimeInterface $datetime = null, ?int $batchSize = null, int $offset = 0, bool $andFlush = true): array
    {
        return $this->inner->revokeAllInvalidBatch($datetime, $batchSize, $offset, $andFlush);
    }

    public function getClass(): string
    {
        return $this->inner->getClass();
    }

    public function revokeAllForUser(UserInterface $user): int
    {
        return $this->inner->revokeAllForUser($user);
    }

    public function revokeAllButNewestForUser(UserInterface $user, int $keep): int
    {
        return $this->inner->revokeAllButNewestForUser($user, $keep);
    }

    public function revokeFamily(string $family): int
    {
        return $this->inner->revokeFamily($family);
    }

    public function findAllForUser(UserInterface $user): array
    {
        return $this->inner->findAllForUser($user);
    }
}
