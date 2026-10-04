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
    use EmailTokenTestTrait;
    use JsonResponseTestTrait;
    use MailerAssertionsTrait;
    use RateLimiterTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purgeDatabase();
        $this->clearRateLimiters();
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
        $members = $this->members();
        self::assertCount(2, $members);

        $this->client->request('GET', '/api/stamps?country=CZ&type=regular', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $filteredMembers = $this->members();
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
        $tagMembers = $this->members();
        self::assertNotEmpty($tagMembers);
        self::assertSame('Hory', $tagMembers[0]['name'] ?? null);

        $this->client->request('GET', '/api/stamp_places', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $placeMembers = $this->members();
        self::assertNotEmpty($placeMembers);
        self::assertSame('Chatová služba', $placeMembers[0]['name'] ?? null);
        self::assertArrayNotHasKey('catalogKey', $placeMembers[0]);
    }

    public function testCatalogSearchOrderItemAndRejectsWrites(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist((new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Annual)
            ->setNumber(9)
            ->setName('Praděd výroční'));
        $em->flush();
        $em->clear();

        $token = $this->authenticate();
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ];

        $this->client->request('GET', '/api/stamps?name=Prad', server: $headers);
        self::assertResponseIsSuccessful();
        $byName = $this->members();
        $names = array_column($byName, 'name');
        sort($names);
        self::assertSame(['Praděd', 'Praděd výroční'], $names);

        $this->client->request('GET', '/api/stamps?country=CZ&type=annual&number=9', server: $headers);
        self::assertResponseIsSuccessful();
        $annual = $this->members();
        self::assertCount(1, $annual);
        self::assertSame('Praděd výroční', $annual[0]['name'] ?? null);
        self::assertSame(9, $annual[0]['number'] ?? null);

        $this->client->request('GET', '/api/stamps?order%5Bnumber%5D=desc', server: $headers);
        self::assertResponseIsSuccessful();
        $ordered = $this->members();
        self::assertSame(9, $ordered[0]['number'] ?? null);
        self::assertSame(1, $ordered[\array_key_last($ordered)]['number'] ?? null);

        $this->client->request('GET', '/api/stamps?page=1', server: $headers);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/stamps?not_a_real_param=1', server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $iri = $annual[0]['@id'] ?? null;
        self::assertIsString($iri);
        $path = parse_url($iri, \PHP_URL_PATH);
        self::assertIsString($path);
        $this->client->request('GET', $path, server: $headers);
        self::assertResponseIsSuccessful();
        $item = $this->jsonResponse();
        self::assertSame('Praděd výroční', $item['name'] ?? null);
        self::assertSame('annual', $item['type'] ?? null);
        self::assertSame('CZ', $item['country'] ?? null);

        $this->client->request('GET', '/api/stamps/999999', server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', '/api/stamps', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], content: json_encode([
            'name' => 'Injected',
            'number' => 100,
            'country' => 'CZ',
            'type' => 'regular',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->client->request('DELETE', $path, server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->client->request('GET', $path, server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('Praděd výroční', $this->jsonResponse()['name'] ?? null);
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

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();

        $tokens = $this->jsonResponse();
        $token = $tokens['token'] ?? null;
        if (!\is_string($token)) {
            self::fail('Expected login response with token string.');
        }

        return $token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(): array
    {
        $payload = $this->jsonResponse();
        $raw = $payload['member'] ?? $payload['hydra:member'] ?? null;
        if (!\is_array($raw)) {
            self::fail('Expected member collection to be an array.');
        }

        $members = [];
        foreach (array_values($raw) as $item) {
            if (!\is_array($item)) {
                self::fail('Expected each member to be an array.');
            }

            $member = [];
            foreach ($item as $key => $value) {
                if (!\is_string($key)) {
                    self::fail('Expected member keys to be strings.');
                }
                $member[$key] = $value;
            }
            $members[] = $member;
        }

        return $members;
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
