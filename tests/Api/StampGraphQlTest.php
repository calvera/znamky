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

final class StampGraphQlTest extends WebTestCase
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

    public function testStampQueriesRequireAuthentication(): void
    {
        $payload = $this->graphql('{ stamps { edges { node { name } } } }');

        if (Response::HTTP_UNAUTHORIZED === $this->client->getResponse()->getStatusCode()) {
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

            return;
        }

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('errors', $payload);
    }

    public function testGraphiqlIsPublic(): void
    {
        $this->client->request('GET', '/api/graphql/graphiql');
        self::assertResponseIsSuccessful();
    }

    public function testStampCollectionQueryAndNoCatalogMutations(): void
    {
        $token = $this->authenticate();

        $payload = $this->graphql(<<<'GRAPHQL'
            {
              stamps(country: "CZ", type: "regular") {
                edges {
                  node {
                    name
                    number
                  }
                }
              }
            }
            GRAPHQL, $token);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $payload);
        $data = $this->stringKeyedArray($payload['data'] ?? null, 'GraphQL data');
        $stamps = $this->stringKeyedArray($data['stamps'] ?? null, 'GraphQL stamps');
        $edges = $stamps['edges'] ?? null;
        if (!\is_array($edges)) {
            self::fail('Expected GraphQL stamp edges array.');
        }
        self::assertCount(1, $edges);
        $firstEdge = $edges[0] ?? null;
        if (!\is_array($firstEdge)) {
            self::fail('Expected first GraphQL edge to be an array.');
        }
        $node = $this->stringKeyedArray($firstEdge['node'] ?? null, 'GraphQL stamp node');
        self::assertSame('Praděd', $node['name'] ?? null);
        self::assertSame(1, $node['number'] ?? null);

        $schema = $this->graphql(<<<'GRAPHQL'
            {
              __schema {
                mutationType {
                  fields {
                    name
                  }
                }
              }
            }
            GRAPHQL, $token);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $schema);
        $schemaData = $this->stringKeyedArray($schema['data'] ?? null, 'GraphQL schema data');
        $schemaRoot = $this->stringKeyedArray($schemaData['__schema'] ?? null, 'GraphQL __schema');
        $mutationType = $schemaRoot['mutationType'] ?? null;
        $mutationFields = [];
        if (\is_array($mutationType) && \is_array($mutationType['fields'] ?? null)) {
            foreach ($mutationType['fields'] as $field) {
                if (\is_array($field) && \is_string($field['name'] ?? null)) {
                    $mutationFields[] = $field['name'];
                }
            }
        }

        foreach (['createStamp', 'updateStamp', 'deleteStamp', 'createStampTag', 'createStampPlace'] as $forbidden) {
            self::assertNotContains($forbidden, $mutationFields, 'Stamp catalog must not expose write mutations');
        }
        self::assertContains('registerUser', $mutationFields);
        self::assertContains('loginUser', $mutationFields);
    }

    public function testFiltersAndRoundedCoordinates(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $latitude = 50.123456789;
        $longitude = -14.987654321;
        $em->persist((new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Annual)
            ->setNumber(9)
            ->setName('Praděd výroční')
            ->setLatitude($latitude)
            ->setLongitude($longitude));
        $em->flush();
        $em->clear();

        $token = $this->authenticate();

        $rounded = $this->graphql(<<<'GRAPHQL'
            {
              stamps(country: "CZ", type: "annual", number: "9") {
                edges {
                  node {
                    name
                    latitude
                    longitude
                  }
                }
              }
            }
            GRAPHQL, $token);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $rounded);
        $node = $this->firstStampNode($rounded);
        self::assertSame('Praděd výroční', $node['name'] ?? null);
        self::assertSame(\sprintf('%.7F', $latitude), $node['latitude'] ?? null);
        self::assertSame(\sprintf('%.7F', $longitude), $node['longitude'] ?? null);

        $exactSlug = $this->graphql(<<<'GRAPHQL'
            { stampTags(slug: "hory") { edges { node { name } } } }
            GRAPHQL, $token);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $exactSlug);
        self::assertSame(['Hory'], $this->nodeNames($exactSlug, 'stampTags'));

        $prefixSlug = $this->graphql(<<<'GRAPHQL'
            { stampTags(slug: "hor") { edges { node { name } } } }
            GRAPHQL, $token);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $prefixSlug);
        self::assertSame([], $this->nodeNames($prefixSlug, 'stampTags'));

        $places = $this->graphql(<<<'GRAPHQL'
            { stampPlaces(name: "Chat") { edges { node { name } } } }
            GRAPHQL, $token);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $places);
        self::assertSame(['Chatová služba'], $this->nodeNames($places, 'stampPlaces'));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function firstStampNode(array $payload): array
    {
        $nodes = $this->nodes($payload, 'stamps');
        $node = $nodes[0] ?? null;
        if (null === $node) {
            self::fail('Expected a stamp node.');
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function nodeNames(array $payload, string $field): array
    {
        $names = [];
        foreach ($this->nodes($payload, $field) as $node) {
            $name = $node['name'] ?? null;
            if (!\is_string($name)) {
                self::fail('Expected node name to be a string.');
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(array $payload, string $field): array
    {
        $data = $this->stringKeyedArray($payload['data'] ?? null, 'GraphQL data');
        $connection = $this->stringKeyedArray($data[$field] ?? null, 'GraphQL '.$field);
        $edges = $connection['edges'] ?? null;
        if (!\is_array($edges)) {
            self::fail('Expected GraphQL edges array.');
        }

        $nodes = [];
        foreach ($edges as $edge) {
            if (!\is_array($edge)) {
                self::fail('Expected GraphQL edge to be an array.');
            }
            $nodes[] = $this->stringKeyedArray($edge['node'] ?? null, 'GraphQL node');
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function graphql(string $query, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request(
            'POST',
            '/api/graphql',
            server: $server,
            content: json_encode(['query' => $query], \JSON_THROW_ON_ERROR),
        );

        return $this->jsonResponse();
    }

    private function authenticate(): string
    {
        $email = 'graphql@example.com';
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
