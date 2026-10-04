<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Stamp;
use App\Entity\StampPlace;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use App\Service\Stamp\GoogleGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class StampCatalogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $this->em = $em;
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE stamp_stamp_tag, stamp_stamp_place, stamps, stamp_tags, stamp_places RESTART IDENTITY CASCADE',
        );
        $this->em->clear();
    }

    public function testImportRefusesToRunWhenCatalogLockIsHeld(): void
    {
        $dir = $this->csvDirectory();
        $lock = $this->holdCatalogLock();

        try {
            $tester = $this->tester('app:stamps:import');
            self::assertSame(Command::FAILURE, $tester->execute(['--path' => $dir]));
            self::assertStringContainsString('already running', $tester->getDisplay());
            self::assertSame(0, $this->stamps()->count([]));
        } finally {
            $lock->release();
            $this->removeDirectory($dir);
        }
    }

    public function testImportResolvesRelativePathAndReleasesLock(): void
    {
        $relative = 'var/missing-stamp-import-'.uniqid('', true);
        $tester = $this->tester('app:stamps:import');

        try {
            $tester->execute(['--path' => $relative]);
            self::fail('A missing import directory must abort the command.');
        } catch (\InvalidArgumentException $e) {
            $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
            self::assertStringContainsString($projectDir.\DIRECTORY_SEPARATOR.$relative, $e->getMessage());
        }

        $lock = $this->locks()->createLock('stamps-catalog');
        self::assertTrue($lock->acquire(false));
        $lock->release();
    }

    public function testImportKeepsAbsolutePathAndPurgesCatalog(): void
    {
        $dir = $this->csvDirectory();
        $extra = (new Stamp())
            ->setCountry(StampCountry::Sk)
            ->setType(StampType::Annual)
            ->setNumber(99)
            ->setName('Doomed');
        $this->em->persist($extra);
        $this->em->flush();

        try {
            $tester = $this->tester('app:stamps:import');
            self::assertSame(Command::SUCCESS, $tester->execute([
                '--path' => $dir,
                '--purge' => true,
            ]));
            self::assertStringContainsString('Purging existing stamp catalog', $tester->getDisplay());
            self::assertStringContainsString('Imported 1 stamps from 1 file(s)', $tester->getDisplay());
        } finally {
            $this->removeDirectory($dir);
        }

        self::assertNull($this->stamps()->findOneByIdentity(StampCountry::Sk, StampType::Annual, 99));
        $stamp = $this->stamps()->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertSame('Z příkazu', $stamp->getName());
    }

    public function testGeocodeRejectsUnknownTargetWithoutTakingTheLock(): void
    {
        $lock = $this->holdCatalogLock();

        try {
            $tester = $this->tester('app:stamps:geocode');
            try {
                $tester->execute([
                    '--only' => 'countries',
                    '--delay' => '0',
                ]);
                self::fail('An unknown --only value must abort the command.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('--only must be one of', $e->getMessage());
                self::assertStringNotContainsString('already running', $tester->getDisplay());
            }
        } finally {
            $lock->release();
        }
    }

    public function testGeocodeRefusesToRunWhenCatalogLockIsHeld(): void
    {
        $this->seedStamp();
        $calls = [];
        $this->replaceGeocoder($calls);
        $lock = $this->holdCatalogLock();

        try {
            $tester = $this->tester('app:stamps:geocode');
            self::assertSame(Command::FAILURE, $tester->execute([
                '--only' => 'stamps',
                '--delay' => '0',
            ]));
            self::assertStringContainsString('already running', $tester->getDisplay());
            self::assertSame([], $calls);
        } finally {
            $lock->release();
        }
    }

    public function testGeocodeOnlyFlagSelectsStampsOrPlaces(): void
    {
        $this->seedStamp();
        $this->em->persist((new StampPlace())->setName('Informační centrum'));
        $this->em->flush();
        $calls = [];
        $this->replaceGeocoder($calls);

        $stamps = $this->tester('app:stamps:geocode');
        self::assertSame(Command::SUCCESS, $stamps->execute([
            '--only' => 'stamps',
            '--delay' => '0',
        ]));
        self::assertStringContainsString('Stamps: 1 geocoded, 0 skipped, 0 failed', $stamps->getDisplay());
        self::assertStringNotContainsString('Places:', $stamps->getDisplay());
        self::assertSame(['Praděd, Moravskoslezský kraj, Czech Republic'], $calls);

        $place = $this->places()->findOneByNameAndUrl('Informační centrum', null);
        self::assertInstanceOf(StampPlace::class, $place);
        self::assertNull($place->getLatitude());

        $places = $this->tester('app:stamps:geocode');
        self::assertSame(Command::SUCCESS, $places->execute([
            '--only' => 'places',
            '--delay' => '0',
        ]));
        self::assertStringContainsString('Places: 1 geocoded, 0 skipped, 0 failed', $places->getDisplay());
        self::assertStringNotContainsString('Stamps:', $places->getDisplay());

        $place = $this->places()->findOneByNameAndUrl('Informační centrum', null);
        self::assertInstanceOf(StampPlace::class, $place);
        self::assertSame(50.5, $place->getLatitude());
    }

    public function testGeocodeForceRewritesExistingCoordinates(): void
    {
        $this->seedStamp(10.0);
        $calls = [];
        $this->replaceGeocoder($calls);

        $skipped = $this->tester('app:stamps:geocode');
        self::assertSame(Command::SUCCESS, $skipped->execute([
            '--only' => 'stamps',
            '--delay' => '0',
        ]));
        self::assertStringContainsString('Stamps: 0 geocoded, 0 skipped, 0 failed', $skipped->getDisplay());
        self::assertSame([], $calls);

        $forced = $this->tester('app:stamps:geocode');
        self::assertSame(Command::SUCCESS, $forced->execute([
            '--only' => 'stamps',
            '--force' => true,
            '--delay' => '0',
        ]));
        self::assertStringContainsString('Stamps: 1 geocoded, 0 skipped, 0 failed', $forced->getDisplay());

        $stamp = $this->stamps()->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertSame(50.5, $stamp->getLatitude());
        self::assertSame(17.5, $stamp->getLongitude());
    }

    private function tester(string $name): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);

        return new CommandTester($application->find($name));
    }

    private function locks(): LockFactory
    {
        /** @var LockFactory $factory */
        $factory = static::getContainer()->get(LockFactory::class);

        return $factory;
    }

    private function holdCatalogLock(): LockInterface
    {
        $lock = $this->locks()->createLock('stamps-catalog');
        self::assertTrue($lock->acquire(false));

        return $lock;
    }

    private function seedStamp(?float $latitude = null): void
    {
        $stamp = (new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Regular)
            ->setNumber(1)
            ->setName('Praděd')
            ->setRegion('Moravskoslezský kraj');
        if (null !== $latitude) {
            $stamp->setLatitude($latitude)->setLongitude(20.0);
        }

        $this->em->persist($stamp);
        $this->em->flush();
    }

    /**
     * @param list<string> $calls
     */
    private function replaceGeocoder(array &$calls): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $query = $options['query'] ?? null;
            if (!\is_array($query)) {
                self::fail('Expected query options array.');
            }
            $address = $query['address'] ?? null;
            if (!\is_string($address)) {
                self::fail('Expected address query string.');
            }
            $calls[] = $address;

            return new MockResponse(json_encode([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 50.5, 'lng' => 17.5]]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        static::getContainer()->set(GoogleGeocoder::class, new GoogleGeocoder($client, 'test-key'));
    }

    private function stamps(): StampRepository
    {
        /** @var StampRepository $repository */
        $repository = static::getContainer()->get(StampRepository::class);

        return $repository;
    }

    private function places(): StampPlaceRepository
    {
        /** @var StampPlaceRepository $repository */
        $repository = static::getContainer()->get(StampPlaceRepository::class);

        return $repository;
    }

    private function csvDirectory(): string
    {
        $dir = sys_get_temp_dir().'/znamky_cmd_'.uniqid('', true);
        mkdir($dir);
        file_put_contents($dir.'/cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ"
"1";"Z příkazu";"Česká Republika";"Turistická známka"
CSV);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
