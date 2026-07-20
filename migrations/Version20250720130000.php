<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250720130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create players, accounts and transfers tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE players (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                futbin_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                rating SMALLINT DEFAULT NULL,
                position VARCHAR(16) DEFAULT NULL,
                price_ps INT DEFAULT NULL,
                price_xbox INT DEFAULT NULL,
                price_pc INT DEFAULT NULL,
                futbin_url VARCHAR(1024) NOT NULL,
                is_favorite TINYINT(1) DEFAULT 0 NOT NULL,
                price_updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_futbin_id (futbin_id),
                INDEX idx_is_favorite (is_favorite),
                INDEX idx_price_updated_at (price_updated_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                email VARCHAR(255) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                balance INT DEFAULT NULL,
                cooldown_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                daily_sales_count SMALLINT DEFAULT 0 NOT NULL,
                daily_sales_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                is_active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_account_email (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE transfers (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                receiver_account_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                sender_account_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                target_amount INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                plan JSON DEFAULT NULL,
                error_message LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_transfer_status (status),
                INDEX idx_transfer_created_at (created_at),
                INDEX IDX_802A3918CD9CFB10 (receiver_account_id),
                INDEX IDX_802A3918C0EA171E (sender_account_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE transfers ADD CONSTRAINT FK_802A3918CD9CFB10 FOREIGN KEY (receiver_account_id) REFERENCES accounts (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfers ADD CONSTRAINT FK_802A3918C0EA171E FOREIGN KEY (sender_account_id) REFERENCES accounts (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transfers DROP FOREIGN KEY FK_802A3918CD9CFB10');
        $this->addSql('ALTER TABLE transfers DROP FOREIGN KEY FK_802A3918C0EA171E');
        $this->addSql('DROP TABLE transfers');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE players');
    }
}
