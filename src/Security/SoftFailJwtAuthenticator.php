<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\JWTAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Invalid/expired/blocklisted Bearer tokens become anonymous instead of 401.
 * Protected routes still require IS_AUTHENTICATED_FULLY via access_control / operation security.
 */
final class SoftFailJwtAuthenticator extends JWTAuthenticator
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
