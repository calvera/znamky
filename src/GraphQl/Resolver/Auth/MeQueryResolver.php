<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\QueryItemResolverInterface;
use App\ApiResource\Auth;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Webmozart\Assert\Assert;

final class MeQueryResolver implements QueryItemResolverInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Access Denied.');
        }

        $id = Assert::notNull($user->getId(), 'Authenticated user must have an id.');

        return Auth::me($id, $user->getEmail(), $user->getRoles());
    }
}
