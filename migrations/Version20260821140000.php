<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add login_status, last_login_at, last_login_error to accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE accounts ADD login_status VARCHAR(16) DEFAULT 'unknown' NOT NULL");
        $this->addSql('ALTER TABLE accounts ADD last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE accounts ADD last_login_error LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP login_status');
        $this->addSql('ALTER TABLE accounts DROP last_login_at');
        $this->addSql('ALTER TABLE accounts DROP last_login_error');
    }
}
