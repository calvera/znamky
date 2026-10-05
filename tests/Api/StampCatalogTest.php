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

use function Safe\parse_url;

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

    public function testSortWhitelistAndExactCatalogFilters(): void
    {
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authenticate(),
            'HTTP_ACCEPT' => 'application/ld+json',
        ];

        $this->client->request('GET', '/api/stamps?order%5Bname%5D=asc', server: $headers);
        self::assertResponseIsSuccessful();
        $notOrderedByName = $this->members();
        self::assertSame('Praděd', $notOrderedByName[0]['name'] ?? null);
        self::assertSame('Kriváň', $notOrderedByName[1]['name'] ?? null);

        $this->client->request('GET', '/api/stamps?order%5Bnumber%5D=ASC', server: $headers);
        self::assertResponseIsSuccessful();
        $ordered = $this->members();
        self::assertSame(1, $ordered[0]['number'] ?? null);

        $this->client->request('GET', '/api/stamp_tags?slug=hory', server: $headers);
        self::assertResponseIsSuccessful();
        $exactSlug = $this->members();
        self::assertCount(1, $exactSlug);
        self::assertSame('Hory', $exactSlug[0]['name'] ?? null);

        $this->client->request('GET', '/api/stamp_tags?slug=hor', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->members());

        $this->client->request('GET', '/api/stamp_tags?name=Hor', server: $headers);
        self::assertResponseIsSuccessful();
        $partialName = $this->members();
        self::assertCount(1, $partialName);
        self::assertSame('Hory', $partialName[0]['name'] ?? null);

        $this->client->request('GET', '/api/stamp_places?name=Chat', server: $headers);
        self::assertResponseIsSuccessful();
        $places = $this->members();
        self::assertCount(1, $places);
        self::assertSame('Chatová služba', $places[0]['name'] ?? null);

        $this->client->request('GET', '/api/stamp_places?name=Nope', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->members());

        $this->client->request('GET', '/api/stamp_tags?not_a_real_param=1', server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->client->request('GET', '/api/stamp_places?not_a_real_param=1', server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCoordinatesRoundToSevenDecimalsAndSerializeAsNumbers(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $stamp = $em->getRepository(Stamp::class)->findOneBy([
            'country' => StampCountry::Cz,
            'type' => StampType::Regular,
            'number' => 1,
        ]);
        $place = $em->getRepository(StampPlace::class)->findOneBy(['name' => 'Chatová služba']);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertInstanceOf(StampPlace::class, $place);

        $stampLatitude = 50.123456789;
        $stampLongitude = -14.987654321;
        $placeLatitude = 49.00000015;
        $expectedStampLatitude = \sprintf('%.7F', $stampLatitude);
        $expectedStampLongitude = \sprintf('%.7F', $stampLongitude);
        $expectedPlaceLatitude = \sprintf('%.7F', $placeLatitude);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{7}$/', $expectedStampLatitude);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{7}$/', $expectedStampLongitude);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{7}$/', $expectedPlaceLatitude);

        $stamp->setLatitude($stampLatitude)->setLongitude($stampLongitude);
        $place->setLatitude($placeLatitude)->setLongitude(null);
        $em->flush();
        $stampId = $stamp->getId();
        $placeId = $place->getId();
        self::assertNotNull($stampId);
        self::assertNotNull($placeId);
        $em->clear();

        $connection = $em->getConnection();
        $storedStampLat = $connection->fetchOne('SELECT latitude FROM stamps WHERE id = ?', [$stampId]);
        $storedStampLng = $connection->fetchOne('SELECT longitude FROM stamps WHERE id = ?', [$stampId]);
        $storedPlaceLat = $connection->fetchOne('SELECT latitude FROM stamp_places WHERE id = ?', [$placeId]);
        $storedPlaceLng = $connection->fetchOne('SELECT longitude FROM stamp_places WHERE id = ?', [$placeId]);
        self::assertSame($expectedStampLatitude, $storedStampLat);
        self::assertSame($expectedStampLongitude, $storedStampLng);
        self::assertSame($expectedPlaceLatitude, $storedPlaceLat);
        self::assertNull($storedPlaceLng);

        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authenticate(),
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
        $this->client->request('GET', '/api/stamps/'.$stampId, server: $headers);
        self::assertResponseIsSuccessful();
        $item = $this->jsonResponse();
        $this->assertJsonNumber($item['latitude'] ?? null, (float) $expectedStampLatitude);
        $this->assertJsonNumber($item['longitude'] ?? null, (float) $expectedStampLongitude);
        self::assertDoesNotMatchRegularExpression(
            '/"latitude"\s*:\s*"/',
            (string) $this->client->getResponse()->getContent(),
        );

        $places = $item['places'] ?? null;
        self::assertIsArray($places);
        $embedded = $this->embeddedPlace($places);
        if (null === $embedded) {
            $this->client->request('GET', '/api/stamp_places/'.$placeId, server: $headers);
            self::assertResponseIsSuccessful();
            $embedded = $this->jsonResponse();
        }
        $this->assertJsonNumber($embedded['latitude'] ?? null, (float) $expectedPlaceLatitude);
        self::assertNull($embedded['longitude'] ?? null);
    }

    private function assertJsonNumber(mixed $value, float $expected): void
    {
        self::assertIsFloat($value);
        self::assertEqualsWithDelta($expected, $value, 0.00000005);
    }

    /**
     * @param array<mixed> $places
     *
     * @return array<string, mixed>|null
     */
    private function embeddedPlace(array $places): ?array
    {
        foreach ($places as $place) {
            if (!\is_array($place)) {
                continue;
            }
            if (\array_key_exists('latitude', $place)) {
                $embedded = [];
                foreach ($place as $key => $value) {
                    if (!\is_string($key)) {
                        self::fail('Expected place keys to be strings.');
                    }
                    $embedded[$key] = $value;
                }

                return $embedded;
            }
        }

        return null;
    }

    private function authenticate(): string
    {
        $email = 'stamps@example.com';
        $password = 'password123';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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
