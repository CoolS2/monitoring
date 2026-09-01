<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widens the columns that hold free-form text.
 *
 * `check_errors.message` stores a checker's message (container lists, SSH
 * errors, aggregated log counts) and `llm_analyses.summary` /
 * `llm_analyses.probable_cause` store model output; none of them fit reliably
 * in the original VARCHAR(255).
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen check_errors.message and llm_analyses summary/probable_cause to TEXT';
    }

    public function up(Schema $schema): void
    {
        if ($this->platform instanceof SQLitePlatform) {
            // SQLite cannot alter a column type in place; the tables are rebuilt.
            $this->rebuildCheckErrorsSqlite();
            $this->rebuildLlmAnalysesSqlite();

            return;
        }

        $this->addSql('ALTER TABLE check_errors ALTER COLUMN message TYPE TEXT');
        $this->addSql('ALTER TABLE llm_analyses ALTER COLUMN summary TYPE TEXT');
        $this->addSql('ALTER TABLE llm_analyses ALTER COLUMN probable_cause TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Narrowing these columns back to VARCHAR(255) would truncate stored data.'
        );
    }

    private function rebuildCheckErrorsSqlite(): void
    {
        $this->addSql('CREATE TABLE __tmp_check_errors AS SELECT id, check_key, message, details, created_at, resolved_at FROM check_errors');
        $this->addSql('DROP TABLE check_errors');
        $this->addSql('CREATE TABLE check_errors (id VARCHAR(36) NOT NULL, check_key VARCHAR(255) NOT NULL, message CLOB NOT NULL, details CLOB DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO check_errors (id, check_key, message, details, created_at, resolved_at) SELECT id, check_key, message, details, created_at, resolved_at FROM __tmp_check_errors');
        $this->addSql('DROP TABLE __tmp_check_errors');
        $this->addSql('CREATE INDEX idx_check_errors_check_key ON check_errors (check_key)');
        $this->addSql('CREATE INDEX idx_check_errors_resolved_at ON check_errors (resolved_at)');
    }

    private function rebuildLlmAnalysesSqlite(): void
    {
        $this->addSql('CREATE TABLE __tmp_llm_analyses AS SELECT id, check_error_id, prompt, raw_response, summary, probable_cause, severity, recommendations, created_at FROM llm_analyses');
        $this->addSql('DROP TABLE llm_analyses');
        $this->addSql('CREATE TABLE llm_analyses (id VARCHAR(36) NOT NULL, check_error_id VARCHAR(36) DEFAULT NULL, prompt CLOB NOT NULL, raw_response CLOB NOT NULL, summary CLOB NOT NULL, probable_cause CLOB NOT NULL, severity VARCHAR(50) NOT NULL, recommendations CLOB NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_LLM_ANALYSES_ERROR_ID FOREIGN KEY (check_error_id) REFERENCES check_errors (id) ON DELETE CASCADE)');
        $this->addSql('INSERT INTO llm_analyses (id, check_error_id, prompt, raw_response, summary, probable_cause, severity, recommendations, created_at) SELECT id, check_error_id, prompt, raw_response, summary, probable_cause, severity, recommendations, created_at FROM __tmp_llm_analyses');
        $this->addSql('DROP TABLE __tmp_llm_analyses');
        $this->addSql('CREATE INDEX idx_llm_analyses_check_error_id ON llm_analyses (check_error_id)');
    }
}
