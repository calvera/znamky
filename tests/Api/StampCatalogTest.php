<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Stamp;
use App\Entity\StampPlace;
use App\Entity\StampTag;
use App\Enum\StampCountry;
use App\Enum\StampType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class StampCatalogTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purgeDatabase();
        $this->seedCatalog();
    }

    public function testStampEndpointsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/stamps');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/stamp_tags');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/stamp_places');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testStampCollectionAndFilters(): void
    {
        $token = $this->authenticate();

        $this->client->request('GET', '/api/stamps', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        $members = $payload['member'] ?? $payload['hydra:member'] ?? [];
        self::assertCount(2, $members);

        $this->client->request('GET', '/api/stamps?country=CZ&type=regular', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $filtered = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        $filteredMembers = $filtered['member'] ?? $filtered['hydra:member'] ?? [];
        self::assertCount(1, $filteredMembers);
        self::assertSame('Praděd', $filteredMembers[0]['name'] ?? null);
    }

    public function testStampTagsAndPlacesCollections(): void
    {
        $token = $this->authenticate();

        $this->client->request('GET', '/api/stamp_tags', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $tags = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        $tagMembers = $tags['member'] ?? $tags['hydra:member'] ?? [];
        self::assertNotEmpty($tagMembers);
        self::assertSame('Hory', $tagMembers[0]['name'] ?? null);

        $this->client->request('GET', '/api/stamp_places', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $places = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        $placeMembers = $places['member'] ?? $places['hydra:member'] ?? [];
        self::assertNotEmpty($placeMembers);
        self::assertSame('Chatová služba', $placeMembers[0]['name'] ?? null);
        self::assertArrayNotHasKey('catalogKey', $placeMembers[0]);
    }

    private function authenticate(): string
    {
        $email = 'stamps@example.com';
        $password = 'password123';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);

        $emailMessage = self::getMailerMessage();
        self::assertNotNull($emailMessage);
        $body = method_exists($emailMessage, 'getHtmlBody') ? ($emailMessage->getHtmlBody() ?: '') : (string) $emailMessage;
        if ('' === $body && method_exists($emailMessage, 'toString')) {
            $body = $emailMessage->toString();
        }
        self::assertMatchesRegularExpression('/([a-f0-9]{64})/', $body);
        preg_match('/([a-f0-9]{64})/', $body, $matches);

        $this->jsonRequest('POST', '/api/verify-email', ['token' => $matches[1]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token: string} $tokens */
        $tokens = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);

        return $tokens['token'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $method, string $uri, array $payload = []): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function seedCatalog(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $tag = (new StampTag())
            ->setName('Hory')
            ->setSlug('hory');
        $place = (new StampPlace())
            ->setName('Chatová služba')
            ->setUrl('https://example.com');

        $stamp1 = (new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Regular)
            ->setNumber(1)
            ->setName('Praděd')
            ->setRegion('Moravskoslezský kraj')
            ->addTag($tag)
            ->addPlace($place);

        $stamp2 = (new Stamp())
            ->setCountry(StampCountry::Sk)
            ->setType(StampType::Regular)
            ->setNumber(1)
            ->setName('Kriváň')
            ->setRegion('Žilinský kraj');

        $em->persist($tag);
        $em->persist($place);
        $em->persist($stamp1);
        $em->persist($stamp2);
        $em->flush();
        $em->clear();
    }

    private function purgeDatabase(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $connection->executeStatement('TRUNCATE TABLE stamp_stamp_tag, stamp_stamp_place, stamps, stamp_tags, stamp_places, refresh_tokens, users RESTART IDENTITY CASCADE');
        $em->clear();
    }
}
