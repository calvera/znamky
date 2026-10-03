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
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purgeDatabase();
        $this->seedCatalog();
    }

    public function testGraphQlRequiresAuthentication(): void
    {
        $this->graphql('{ stamps { edges { node { name } } } }');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGraphiqlIsPublic(): void
    {
        $this->client->request('GET', '/api/graphql/graphiql');
        self::assertResponseIsSuccessful();
    }

    public function testStampCollectionQueryAndNoMutations(): void
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
        $edges = $payload['data']['stamps']['edges'] ?? [];
        self::assertCount(1, $edges);
        self::assertSame('Praděd', $edges[0]['node']['name'] ?? null);
        self::assertSame(1, $edges[0]['node']['number'] ?? null);

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
        $mutationFields = $schema['data']['__schema']['mutationType']['fields'] ?? null;
        self::assertTrue(
            null === $mutationFields || [] === $mutationFields,
            'Stamp catalog must not expose GraphQL mutations',
        );
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

        $content = $this->client->getResponse()->getContent() ?: '{}';

        return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function authenticate(): string
    {
        $email = 'graphql@example.com';
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

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $matches[1],
            'password' => $password,
        ]);
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
