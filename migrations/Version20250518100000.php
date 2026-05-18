<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250518100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create game_items table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE game_items (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                external_id INT NOT NULL,
                title VARCHAR(512) NOT NULL,
                description LONGTEXT NOT NULL,
                category VARCHAR(128) NOT NULL,
                source_url VARCHAR(1024) NOT NULL,
                screenshot_path VARCHAR(512) DEFAULT NULL,
                scraped_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_external_id (external_id),
                INDEX idx_category (category),
                INDEX idx_scraped_at (scraped_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_items');
    }
}
