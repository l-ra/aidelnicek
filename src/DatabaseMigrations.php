<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Spárované DDL migrace: SQLite text musí být bajtově stejný jako v Database.php (historické názvy migration_*).
 */
final class DatabaseMigrations
{
    /**
     * @return list<array{sqlite: string, pgsql: string}>
     */
    public static function pairedSteps(): array
    {
        return [
            [
                'sqlite' => <<<'S_0'
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
S_0,
                'pgsql' => <<<'PG_S_0'
CREATE TABLE IF NOT EXISTS migrations (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
PG_S_0,
            ],
            [
                'sqlite' => <<<'S_1'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                gender TEXT,
                age INTEGER,
                body_type TEXT,
                dietary_notes TEXT,
                is_admin INTEGER DEFAULT 0,
                push_subscription TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
S_1,
                'pgsql' => <<<'PG_S_1'
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    gender TEXT,
    age INTEGER,
    body_type TEXT,
    dietary_notes TEXT,
    is_admin INTEGER DEFAULT 0,
    push_subscription TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
PG_S_1,
            ],
            [
                'sqlite' => <<<'S_2'
            CREATE TABLE IF NOT EXISTS weeks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                week_number INTEGER NOT NULL,
                year INTEGER NOT NULL,
                generated_at DATETIME,
                UNIQUE(week_number, year)
            );
S_2,
                'pgsql' => <<<'PG_S_2'
CREATE TABLE IF NOT EXISTS weeks (
    id SERIAL PRIMARY KEY,
    week_number INTEGER NOT NULL,
    year INTEGER NOT NULL,
    generated_at TIMESTAMPTZ,
    UNIQUE(week_number, year)
)
PG_S_2,
            ],
            [
                'sqlite' => <<<'S_3'
            CREATE TABLE IF NOT EXISTS meal_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                week_id INTEGER NOT NULL REFERENCES weeks(id),
                day_of_week INTEGER NOT NULL,
                meal_type TEXT NOT NULL,
                alternative INTEGER NOT NULL,
                meal_name TEXT NOT NULL,
                description TEXT,
                ingredients TEXT,
                canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id),
                pairing_key TEXT,
                is_chosen INTEGER DEFAULT 0,
                is_eaten INTEGER DEFAULT 0,
                UNIQUE(user_id, week_id, day_of_week, meal_type, alternative)
            );
S_3,
                'pgsql' => <<<'PG_S_3'
CREATE TABLE IF NOT EXISTS meal_plans (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    week_id INTEGER NOT NULL REFERENCES weeks(id),
    day_of_week INTEGER NOT NULL,
    meal_type TEXT NOT NULL,
    alternative INTEGER NOT NULL,
    meal_name TEXT NOT NULL,
    description TEXT,
    ingredients TEXT,
    canonical_proposal_meal_id INTEGER,
    pairing_key TEXT,
    is_chosen INTEGER DEFAULT 0,
    is_eaten INTEGER DEFAULT 0,
    UNIQUE(user_id, week_id, day_of_week, meal_type, alternative)
)
PG_S_3,
            ],
            [
                'sqlite' => <<<'S_4'
            CREATE TABLE IF NOT EXISTS shopping_list_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                week_id INTEGER NOT NULL REFERENCES weeks(id),
                name TEXT NOT NULL,
                quantity REAL,
                unit TEXT,
                category TEXT,
                is_purchased INTEGER DEFAULT 0,
                purchased_by INTEGER REFERENCES users(id),
                added_manually INTEGER DEFAULT 0,
                added_by INTEGER REFERENCES users(id)
            );
S_4,
                'pgsql' => <<<'PG_S_4'
CREATE TABLE IF NOT EXISTS shopping_list_items (
    id SERIAL PRIMARY KEY,
    week_id INTEGER NOT NULL REFERENCES weeks(id),
    name TEXT NOT NULL,
    quantity DOUBLE PRECISION,
    unit TEXT,
    category TEXT,
    is_purchased INTEGER DEFAULT 0,
    purchased_by INTEGER REFERENCES users(id),
    added_manually INTEGER DEFAULT 0,
    added_by INTEGER REFERENCES users(id)
)
PG_S_4,
            ],
            [
                'sqlite' => <<<'S_5'
            CREATE TABLE IF NOT EXISTS meal_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                meal_name TEXT NOT NULL,
                times_offered INTEGER DEFAULT 0,
                times_chosen INTEGER DEFAULT 0,
                times_eaten INTEGER DEFAULT 0,
                last_offered DATETIME
            );
S_5,
                'pgsql' => <<<'PG_S_5'
CREATE TABLE IF NOT EXISTS meal_history (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    meal_name TEXT NOT NULL,
    times_offered INTEGER DEFAULT 0,
    times_chosen INTEGER DEFAULT 0,
    times_eaten INTEGER DEFAULT 0,
    last_offered TIMESTAMPTZ
)
PG_S_5,
            ],
            [
                'sqlite' => <<<'S_6'
            CREATE TABLE IF NOT EXISTS notifications_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                sent_at DATETIME,
                type TEXT,
                status TEXT
            );
S_6,
                'pgsql' => <<<'PG_S_6'
CREATE TABLE IF NOT EXISTS notifications_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    sent_at TIMESTAMPTZ,
    type TEXT,
    status TEXT
)
PG_S_6,
            ],
            [
                'sqlite' => <<<'S_7'
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );
S_7,
                'pgsql' => <<<'PG_S_7'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
)
PG_S_7,
            ],
            [
                'sqlite' => <<<'S_8'
            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                token_hash TEXT NOT NULL,
                expires_at DATETIME NOT NULL
            );
S_8,
                'pgsql' => <<<'PG_S_8'
CREATE TABLE IF NOT EXISTS remember_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL
)
PG_S_8,
            ],
            [
                'sqlite' => <<<'S_9'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_meal_history_user_meal
                ON meal_history(user_id, meal_name);
S_9,
                'pgsql' => <<<'PG_S_9'
CREATE UNIQUE INDEX IF NOT EXISTS idx_meal_history_user_meal
    ON meal_history(user_id, meal_name)
PG_S_9,
            ],
            [
                'sqlite' => <<<'S_10'
            ALTER TABLE users ADD COLUMN height INTEGER;
S_10,
                'pgsql' => <<<'PG_S_10'
ALTER TABLE users ADD COLUMN IF NOT EXISTS height INTEGER
PG_S_10,
            ],
            [
                'sqlite' => <<<'S_11'
            ALTER TABLE users ADD COLUMN weight REAL;
S_11,
                'pgsql' => <<<'PG_S_11'
ALTER TABLE users ADD COLUMN IF NOT EXISTS weight DOUBLE PRECISION
PG_S_11,
            ],
            [
                'sqlite' => <<<'S_12'
            ALTER TABLE users ADD COLUMN diet_goal TEXT;
S_12,
                'pgsql' => <<<'PG_S_12'
ALTER TABLE users ADD COLUMN IF NOT EXISTS diet_goal TEXT
PG_S_12,
            ],
            [
                'sqlite' => <<<'S_13'
            CREATE TABLE IF NOT EXISTS generation_jobs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                week_id       INTEGER NOT NULL,
                status        TEXT    NOT NULL DEFAULT 'pending',
                progress_text TEXT    NOT NULL DEFAULT '',
                chunk_count   INTEGER NOT NULL DEFAULT 0,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                started_at    DATETIME,
                finished_at   DATETIME,
                error_message TEXT
            );
S_13,
                'pgsql' => <<<'PG_S_13'
CREATE TABLE IF NOT EXISTS generation_jobs (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL,
    week_id       INTEGER NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',
    progress_text TEXT    NOT NULL DEFAULT '',
    chunk_count   INTEGER NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at    TIMESTAMPTZ,
    finished_at   TIMESTAMPTZ,
    error_message TEXT
)
PG_S_13,
            ],
            [
                'sqlite' => <<<'S_14'
            CREATE TABLE IF NOT EXISTS llm_meal_proposals (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                week_id            INTEGER NOT NULL REFERENCES weeks(id),
                generation_job_id  INTEGER REFERENCES generation_jobs(id),
                reference_user_id  INTEGER NOT NULL REFERENCES users(id),
                llm_model          TEXT,
                created_at         DATETIME DEFAULT CURRENT_TIMESTAMP
            );
S_14,
                'pgsql' => <<<'PG_S_14'
CREATE TABLE IF NOT EXISTS llm_meal_proposals (
    id                 SERIAL PRIMARY KEY,
    week_id            INTEGER NOT NULL REFERENCES weeks(id),
    generation_job_id  INTEGER REFERENCES generation_jobs(id),
    reference_user_id  INTEGER NOT NULL REFERENCES users(id),
    llm_model          TEXT,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
PG_S_14,
            ],
            [
                'sqlite' => <<<'S_15'
            CREATE TABLE IF NOT EXISTS llm_proposal_meals (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                proposal_id  INTEGER NOT NULL REFERENCES llm_meal_proposals(id) ON DELETE CASCADE,
                day_of_week  INTEGER NOT NULL,
                meal_type    TEXT NOT NULL,
                alternative  INTEGER NOT NULL,
                meal_name    TEXT NOT NULL,
                description  TEXT,
                ingredients  TEXT NOT NULL,
                canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id),
                pairing_key  TEXT,
                UNIQUE(proposal_id, day_of_week, meal_type, alternative)
            );
S_15,
                'pgsql' => <<<'PG_S_15'
CREATE TABLE IF NOT EXISTS llm_proposal_meals (
    id           SERIAL PRIMARY KEY,
    proposal_id  INTEGER NOT NULL REFERENCES llm_meal_proposals(id) ON DELETE CASCADE,
    day_of_week  INTEGER NOT NULL,
    meal_type    TEXT NOT NULL,
    alternative  INTEGER NOT NULL,
    meal_name    TEXT NOT NULL,
    description  TEXT,
    ingredients  TEXT NOT NULL,
    canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id),
    pairing_key  TEXT,
    UNIQUE(proposal_id, day_of_week, meal_type, alternative)
)
PG_S_15,
            ],
            [
                'sqlite' => <<<'S_16'
            CREATE TABLE IF NOT EXISTS meal_recipes (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                proposal_meal_id     INTEGER NOT NULL UNIQUE REFERENCES llm_proposal_meals(id) ON DELETE CASCADE,
                generated_by_user_id INTEGER REFERENCES users(id),
                model                TEXT,
                recipe_text          TEXT NOT NULL,
                created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP
            );
S_16,
                'pgsql' => <<<'PG_S_16'
CREATE TABLE IF NOT EXISTS meal_recipes (
    id                   SERIAL PRIMARY KEY,
    proposal_meal_id     INTEGER NOT NULL UNIQUE REFERENCES llm_proposal_meals(id) ON DELETE CASCADE,
    generated_by_user_id INTEGER REFERENCES users(id),
    model                TEXT,
    recipe_text          TEXT NOT NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
PG_S_16,
            ],
            [
                'sqlite' => <<<'S_17'
            ALTER TABLE meal_plans ADD COLUMN proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id);
S_17,
                'pgsql' => <<<'PG_S_17'
ALTER TABLE meal_plans ADD COLUMN IF NOT EXISTS proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id)
PG_S_17,
            ],
            [
                'sqlite' => <<<'S_18'
            ALTER TABLE meal_plans ADD COLUMN portion_factor REAL NOT NULL DEFAULT 1.0;
S_18,
                'pgsql' => <<<'PG_S_18'
ALTER TABLE meal_plans ADD COLUMN IF NOT EXISTS portion_factor DOUBLE PRECISION NOT NULL DEFAULT 1.0
PG_S_18,
            ],
            [
                'sqlite' => <<<'S_19'
            ALTER TABLE meal_plans ADD COLUMN canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id);
S_19,
                'pgsql' => <<<'PG_S_19'
ALTER TABLE meal_plans ADD COLUMN IF NOT EXISTS canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id)
PG_S_19,
            ],
            [
                'sqlite' => <<<'S_20'
            ALTER TABLE meal_plans ADD COLUMN pairing_key TEXT;
S_20,
                'pgsql' => <<<'PG_S_20'
ALTER TABLE meal_plans ADD COLUMN IF NOT EXISTS pairing_key TEXT
PG_S_20,
            ],
            [
                'sqlite' => <<<'S_21'
            CREATE INDEX IF NOT EXISTS idx_meal_plans_proposal_meal_id
                ON meal_plans(proposal_meal_id);
S_21,
                'pgsql' => <<<'PG_S_21'
CREATE INDEX IF NOT EXISTS idx_meal_plans_proposal_meal_id
    ON meal_plans(proposal_meal_id)
PG_S_21,
            ],
            [
                'sqlite' => <<<'S_22'
            CREATE INDEX IF NOT EXISTS idx_meal_plans_canonical_proposal_meal_id
                ON meal_plans(canonical_proposal_meal_id);
S_22,
                'pgsql' => <<<'PG_S_22'
CREATE INDEX IF NOT EXISTS idx_meal_plans_canonical_proposal_meal_id
    ON meal_plans(canonical_proposal_meal_id)
PG_S_22,
            ],
            [
                'sqlite' => <<<'S_23'
            CREATE INDEX IF NOT EXISTS idx_meal_plans_pairing_key
                ON meal_plans(pairing_key);
S_23,
                'pgsql' => <<<'PG_S_23'
CREATE INDEX IF NOT EXISTS idx_meal_plans_pairing_key
    ON meal_plans(pairing_key)
PG_S_23,
            ],
            [
                'sqlite' => <<<'S_24'
            ALTER TABLE llm_proposal_meals ADD COLUMN canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id);
S_24,
                'pgsql' => <<<'PG_S_24'
ALTER TABLE llm_proposal_meals ADD COLUMN IF NOT EXISTS canonical_proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id)
PG_S_24,
            ],
            [
                'sqlite' => <<<'S_25'
            ALTER TABLE llm_proposal_meals ADD COLUMN pairing_key TEXT;
S_25,
                'pgsql' => <<<'PG_S_25'
ALTER TABLE llm_proposal_meals ADD COLUMN IF NOT EXISTS pairing_key TEXT
PG_S_25,
            ],
            [
                'sqlite' => <<<'S_26'
            CREATE INDEX IF NOT EXISTS idx_llm_proposal_meals_canonical_proposal_meal_id
                ON llm_proposal_meals(canonical_proposal_meal_id);
S_26,
                'pgsql' => <<<'PG_S_26'
CREATE INDEX IF NOT EXISTS idx_llm_proposal_meals_canonical_proposal_meal_id
    ON llm_proposal_meals(canonical_proposal_meal_id)
PG_S_26,
            ],
            [
                'sqlite' => <<<'S_27'
            CREATE INDEX IF NOT EXISTS idx_llm_proposal_meals_pairing_key
                ON llm_proposal_meals(pairing_key);
S_27,
                'pgsql' => <<<'PG_S_27'
CREATE INDEX IF NOT EXISTS idx_llm_proposal_meals_pairing_key
    ON llm_proposal_meals(pairing_key)
PG_S_27,
            ],
            [
                'sqlite' => <<<'S_28'
            ALTER TABLE generation_jobs ADD COLUMN job_type TEXT NOT NULL DEFAULT 'mealplan_generate';
S_28,
                'pgsql' => <<<'PG_S_28'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS job_type TEXT NOT NULL DEFAULT 'mealplan_generate'
PG_S_28,
            ],
            [
                'sqlite' => <<<'S_29'
            ALTER TABLE generation_jobs ADD COLUMN mode TEXT NOT NULL DEFAULT 'async';
S_29,
                'pgsql' => <<<'PG_S_29'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS mode TEXT NOT NULL DEFAULT 'async'
PG_S_29,
            ],
            [
                'sqlite' => <<<'S_30'
            ALTER TABLE generation_jobs ADD COLUMN input_payload TEXT NOT NULL DEFAULT '{}';
S_30,
                'pgsql' => <<<'PG_S_30'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS input_payload TEXT NOT NULL DEFAULT '{}'
PG_S_30,
            ],
            [
                'sqlite' => <<<'S_31'
            ALTER TABLE generation_jobs ADD COLUMN projection_status TEXT NOT NULL DEFAULT 'pending';
S_31,
                'pgsql' => <<<'PG_S_31'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS projection_status TEXT NOT NULL DEFAULT 'pending'
PG_S_31,
            ],
            [
                'sqlite' => <<<'S_32'
            ALTER TABLE generation_jobs ADD COLUMN projection_error_message TEXT;
S_32,
                'pgsql' => <<<'PG_S_32'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS projection_error_message TEXT
PG_S_32,
            ],
            [
                'sqlite' => <<<'S_33'
            ALTER TABLE generation_jobs ADD COLUMN projection_started_at DATETIME;
S_33,
                'pgsql' => <<<'PG_S_33'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS projection_started_at TIMESTAMPTZ
PG_S_33,
            ],
            [
                'sqlite' => <<<'S_34'
            ALTER TABLE generation_jobs ADD COLUMN projection_finished_at DATETIME;
S_34,
                'pgsql' => <<<'PG_S_34'
ALTER TABLE generation_jobs ADD COLUMN IF NOT EXISTS projection_finished_at TIMESTAMPTZ
PG_S_34,
            ],
            [
                'sqlite' => <<<'S_35'
            CREATE TABLE IF NOT EXISTS generation_job_chunks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id     INTEGER NOT NULL REFERENCES generation_jobs(id) ON DELETE CASCADE,
                seq_no     INTEGER NOT NULL,
                chunk_text TEXT    NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(job_id, seq_no)
            );
S_35,
                'pgsql' => <<<'PG_S_35'
CREATE TABLE IF NOT EXISTS generation_job_chunks (
    id         SERIAL PRIMARY KEY,
    job_id     INTEGER NOT NULL REFERENCES generation_jobs(id) ON DELETE CASCADE,
    seq_no     INTEGER NOT NULL,
    chunk_text TEXT    NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(job_id, seq_no)
)
PG_S_35,
            ],
            [
                'sqlite' => <<<'S_36'
            CREATE INDEX IF NOT EXISTS idx_generation_job_chunks_job_seq
                ON generation_job_chunks(job_id, seq_no);
S_36,
                'pgsql' => <<<'PG_S_36'
CREATE INDEX IF NOT EXISTS idx_generation_job_chunks_job_seq
    ON generation_job_chunks(job_id, seq_no)
PG_S_36,
            ],
            [
                'sqlite' => <<<'S_37'
            CREATE TABLE IF NOT EXISTS generation_job_outputs (
                job_id       INTEGER PRIMARY KEY REFERENCES generation_jobs(id) ON DELETE CASCADE,
                provider     TEXT    NOT NULL DEFAULT 'openai',
                model        TEXT    NOT NULL,
                raw_text     TEXT    NOT NULL,
                parsed_json  TEXT,
                tokens_in    INTEGER,
                tokens_out   INTEGER,
                duration_ms  INTEGER,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            );
S_37,
                'pgsql' => <<<'PG_S_37'
CREATE TABLE IF NOT EXISTS generation_job_outputs (
    job_id       INTEGER PRIMARY KEY REFERENCES generation_jobs(id) ON DELETE CASCADE,
    provider     TEXT    NOT NULL DEFAULT 'openai',
    model        TEXT    NOT NULL,
    raw_text     TEXT    NOT NULL,
    parsed_json  TEXT,
    tokens_in    INTEGER,
    tokens_out   INTEGER,
    duration_ms  INTEGER,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
)
PG_S_37,
            ],
            [
                'sqlite' => <<<'S_38'
            CREATE INDEX IF NOT EXISTS idx_generation_jobs_status_projection
                ON generation_jobs(status, projection_status);
S_38,
                'pgsql' => <<<'PG_S_38'
CREATE INDEX IF NOT EXISTS idx_generation_jobs_status_projection
    ON generation_jobs(status, projection_status)
PG_S_38,
            ],
            [
                'sqlite' => <<<'S_39'
            CREATE TABLE IF NOT EXISTS email_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                new_email TEXT NOT NULL,
                old_email TEXT NOT NULL,
                requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME
            );
S_39,
                'pgsql' => <<<'PG_S_39'
CREATE TABLE IF NOT EXISTS email_change_requests (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    new_email TEXT NOT NULL,
    old_email TEXT NOT NULL,
    requested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ
)
PG_S_39,
            ],
            [
                'sqlite' => <<<'S_40'
            CREATE INDEX IF NOT EXISTS idx_email_change_user_pending
                ON email_change_requests(user_id, consumed_at);
S_40,
                'pgsql' => <<<'PG_S_40'
CREATE INDEX IF NOT EXISTS idx_email_change_user_pending
    ON email_change_requests(user_id, consumed_at)
PG_S_40,
            ],
            [
                'sqlite' => <<<'S_41'
            UPDATE llm_proposal_meals
            SET canonical_proposal_meal_id = id
            WHERE canonical_proposal_meal_id IS NULL;
S_41,
                'pgsql' => <<<'PG_S_41'
UPDATE llm_proposal_meals
SET canonical_proposal_meal_id = id
WHERE canonical_proposal_meal_id IS NULL
PG_S_41,
            ],
            [
                'sqlite' => <<<'S_42'
            UPDATE meal_plans
            SET canonical_proposal_meal_id = proposal_meal_id
            WHERE canonical_proposal_meal_id IS NULL
              AND proposal_meal_id IS NOT NULL;
S_42,
                'pgsql' => <<<'PG_S_42'
UPDATE meal_plans
SET canonical_proposal_meal_id = proposal_meal_id
WHERE canonical_proposal_meal_id IS NULL
  AND proposal_meal_id IS NOT NULL
PG_S_42,
            ],
        ];
    }
}
