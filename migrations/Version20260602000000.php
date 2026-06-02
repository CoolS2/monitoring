<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create check_results, check_errors, llm_analyses, and notifications tables';
    }

    public function up(Schema $schema): void
    {
        // SQLite compatible SQL statements
        $this->addSql('CREATE TABLE check_results (id VARCHAR(36) NOT NULL, check_key VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, success BOOLEAN NOT NULL, message CLOB NOT NULL, response_time DOUBLE PRECISION DEFAULT NULL, extra CLOB NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_check_results_check_key ON check_results (check_key)');
        $this->addSql('CREATE INDEX idx_check_results_created_at ON check_results (created_at)');

        $this->addSql('CREATE TABLE check_errors (id VARCHAR(36) NOT NULL, check_key VARCHAR(255) NOT NULL, message VARCHAR(255) NOT NULL, details CLOB DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_check_errors_check_key ON check_errors (check_key)');
        $this->addSql('CREATE INDEX idx_check_errors_resolved_at ON check_errors (resolved_at)');

        $this->addSql('CREATE TABLE llm_analyses (id VARCHAR(36) NOT NULL, check_error_id VARCHAR(36) DEFAULT NULL, prompt CLOB NOT NULL, raw_response CLOB NOT NULL, summary VARCHAR(255) NOT NULL, probable_cause VARCHAR(255) NOT NULL, severity VARCHAR(50) NOT NULL, recommendations CLOB NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_LLM_ANALYSES_ERROR_ID FOREIGN KEY (check_error_id) REFERENCES check_errors (id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX idx_llm_analyses_check_error_id ON llm_analyses (check_error_id)');

        $this->addSql('CREATE TABLE notifications (id VARCHAR(36) NOT NULL, check_key VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, message CLOB NOT NULL, sent_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_notifications_check_key ON notifications (check_key)');
        $this->addSql('CREATE INDEX idx_notifications_sent_at ON notifications (sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE llm_analyses');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE check_errors');
        $this->addSql('DROP TABLE check_results');
    }
}
