<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password, totp_secret and proxy_url to accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ADD password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE accounts ADD totp_secret VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE accounts ADD proxy_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts DROP password');
        $this->addSql('ALTER TABLE accounts DROP totp_secret');
        $this->addSql('ALTER TABLE accounts DROP proxy_url');
    }
}
