<?php

declare(strict_types=1);

namespace App\Security;

use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\RequestBodyExtractor;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads refresh tokens from the JSON body only.
 *
 * Gesdinet's chain also accepts the query string (which lands in access logs) and
 * cookies (sent cross-site); clients here always post the token as JSON.
 */
#[AsDecorator(decorates: 'gesdinet_jwt_refresh_token.request.extractor.chain')]
final class BodyOnlyRefreshTokenExtractor implements ExtractorInterface
{
    private readonly RequestBodyExtractor $body;

    public function __construct()
    {
        $this->body = new RequestBodyExtractor();
    }

    public function getRefreshToken(Request $request, string $parameter): ?string
    {
        return $this->body->getRefreshToken($request, $parameter);
    }
}
