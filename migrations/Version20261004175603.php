<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Change stamp/place coordinates from double precision to DECIMAL(10, 7).
 */
final class Version20261004175603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store stamp and place coordinates as DECIMAL(10, 7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stamp_places ALTER latitude TYPE NUMERIC(10, 7)');
        $this->addSql('ALTER TABLE stamp_places ALTER longitude TYPE NUMERIC(10, 7)');
        $this->addSql('ALTER TABLE stamps ALTER latitude TYPE NUMERIC(10, 7)');
        $this->addSql('ALTER TABLE stamps ALTER longitude TYPE NUMERIC(10, 7)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stamp_places ALTER latitude TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE stamp_places ALTER longitude TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE stamps ALTER latitude TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE stamps ALTER longitude TYPE DOUBLE PRECISION');
    }
}
