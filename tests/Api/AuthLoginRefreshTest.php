<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTime;
use Safe\DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class AuthLoginRefreshTest extends WebTestCase
{
    use AuthApiTestTrait;

    public function testLoginAcceptsEmailCasingUsedAtRegistration(): void
    {
        $email = 'Alice.Smith@Example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);

        $tokens = $this->login($email, $password);
        self::assertNotEmpty($tokens['token']);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();
        $me = $this->jsonResponse();
        self::assertSame(strtolower($email), $me['email']);

        $this->login(strtolower($email), $password);

        $this->jsonRequest('POST', '/api/register', [
            'email' => strtolower($email),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRefreshRotatesToken(): void
    {
        $email = 'refresh@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $tokens = $this->login($email, $password);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $newTokens = $this->jsonResponse();
        self::assertNotSame($tokens['refresh_token'], $newTokens['refresh_token']);
        self::assertNotEmpty($newTokens['token']);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshAcceptsTheTokenOnlyFromTheJsonBody(): void
    {
        $email = 'refresh-body-only@example.com';
        $this->registerAndVerify($email, 'password123');
        $refreshToken = $this->login($email, 'password123')['refresh_token'];

        // Query strings end up in access logs; cookies would be sent cross-site.
        $this->client->request('POST', '/api/token/refresh?refresh_token='.$refreshToken);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->getCookieJar()->set(new Cookie('refresh_token', $refreshToken));
        $this->client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->client->getCookieJar()->clear();

        $this->jsonRequest('POST', '/api/token/refresh', ['refresh_token' => $refreshToken]);
        self::assertResponseIsSuccessful();
    }

    public function testRefreshReuseRevokesTheTokenFamily(): void
    {
        $this->registerAndVerify('refresh-reuse@example.com', 'password123');
        $tokens = $this->login('refresh-reuse@example.com', 'password123');

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $rotated = $this->jsonResponse();
        $newRefresh = $rotated['refresh_token'] ?? null;
        self::assertIsString($newRefresh);
        self::assertNotSame($tokens['refresh_token'], $newRefresh);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $newRefresh,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshReuseDoesNotRevokeAnotherSessionsAccessToken(): void
    {
        $email = 'reuse-other-session@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $replayed = $this->login($email, $password);
        $other = $this->login($email, $password);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $replayed['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $rotated = $this->jsonResponse();
        $rotatedRefresh = $rotated['refresh_token'] ?? null;
        self::assertIsString($rotatedRefresh);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $replayed['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $rotatedRefresh,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$other['token'],
        ]);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $other['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testStoredRefreshTokenDigestCannotBeUsedToRefresh(): void
    {
        $email = 'digest-refresh@example.com';
        $this->registerAndVerify($email, 'password123');
        $tokens = $this->login($email, 'password123');

        $stored = $this->storedRefreshTokenFor($email);
        self::assertNotSame($tokens['refresh_token'], $stored);
        self::assertStringStartsWith('sha256$', $stored);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $stored,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testCleartextRefreshTokenRowCannotBeUsedToRefresh(): void
    {
        $email = 'cleartext-refresh@example.com';
        $this->registerAndVerify($email, 'password123');
        $cleartext = bin2hex(random_bytes(64));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken($cleartext);
        $refreshToken->setUsername($email);
        $refreshToken->setValid(new DateTime('+1 day'));
        $em->persist($refreshToken);
        $em->flush();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $cleartext,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIssuedRefreshTokenIsStoredAsADigest(): void
    {
        $email = 'hashed-refresh@example.com';
        $this->registerAndVerify($email, 'password123');
        $tokens = $this->login($email, 'password123');

        $stored = $this->storedRefreshTokenFor($email);
        self::assertSame('sha256$'.hash('sha256', $tokens['refresh_token']), $stored);
    }

    public function testMeWithoutJwtReturns401(): void
    {
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIssuedAuthTokensExpireOnTheirExpectedWindows(): void
    {
        $email = 'token-lifetime@example.com';
        $registeredAt = new DateTimeImmutable();
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $verificationExpires = $this->findUser($email)->getEmailVerificationTokenExpiresAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $verificationExpires);
        self::assertGreaterThan($registeredAt->modify('+23 hours'), $verificationExpires);
        self::assertLessThan($registeredAt->modify('+25 hours'), $verificationExpires);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $requestedAt = new DateTimeImmutable();
        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $user = $this->findUser($email);
        $resetExpires = $user->getPasswordResetTokenExpiresAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $resetExpires);
        self::assertGreaterThan($requestedAt->modify('+50 minutes'), $resetExpires);
        self::assertLessThan($requestedAt->modify('+70 minutes'), $resetExpires);
        self::assertNull($user->getEmailVerificationToken());
    }

    public function testRefreshRejectsUnverifiedAccountWithoutConsumingTheToken(): void
    {
        $email = 'unverified-refresh@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $tokens = $this->login($email, $password);

        $user = $this->findUser($email);
        $user->setIsVerified(false);
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $message = $this->jsonResponse()['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('Please verify your email', $message);

        $user = $this->findUser($email);
        $user->setIsVerified(true);
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $rotated = $this->jsonResponse();
        self::assertIsString($rotated['refresh_token'] ?? null);
        self::assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
    }

    public function testRefreshDoesNotIssueAUsableTokenPastTheFamilyDeadline(): void
    {
        $email = 'family-deadline@example.com';
        $this->registerAndVerify($email, 'password123');
        $tokens = $this->login($email, 'password123');

        $stored = $this->refreshTokenEntity($email);
        $family = $stored->getFamily();
        self::assertIsString($family);
        self::assertNotSame('', $family);
        $stored->setFamilyValid(new DateTime('-1 minute'));
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $rotated = $this->jsonResponse();
        $newRefresh = $rotated['refresh_token'] ?? null;
        self::assertIsString($newRefresh);
        self::assertNotSame($tokens['refresh_token'], $newRefresh);

        $capped = $this->refreshTokenEntity($email);
        self::assertSame($family, $capped->getFamily());
        $valid = $capped->getValid();
        self::assertInstanceOf(\DateTimeInterface::class, $valid);
        self::assertLessThan(time(), $valid->getTimestamp());

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $newRefresh,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshKeepsTheTtlWhenTheFamilyDeadlineIsLater(): void
    {
        $email = 'family-ttl@example.com';
        $this->registerAndVerify($email, 'password123');
        $tokens = $this->login($email, 'password123');

        $stored = $this->refreshTokenEntity($email);
        $originalValid = $stored->getValid();
        self::assertInstanceOf(\DateTimeInterface::class, $originalValid);
        $family = $stored->getFamily();
        self::assertIsString($family);
        $deadline = new DateTime();
        $deadline->setTimestamp($originalValid->getTimestamp() + 30 * 86400);
        $stored->setFamilyValid($deadline);
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();

        $rotated = $this->refreshTokenEntity($email);
        self::assertSame($family, $rotated->getFamily());
        $valid = $rotated->getValid();
        $familyValid = $rotated->getFamilyValid();
        self::assertInstanceOf(\DateTimeInterface::class, $valid);
        self::assertInstanceOf(\DateTimeInterface::class, $familyValid);
        self::assertEqualsWithDelta($originalValid->getTimestamp(), $valid->getTimestamp(), 60);
        self::assertEqualsWithDelta($deadline->getTimestamp(), $familyValid->getTimestamp(), 2);
        self::assertLessThan($familyValid->getTimestamp() - 86400, $valid->getTimestamp());
    }
}
