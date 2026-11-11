<?php

declare(strict_types=1);

/** Création des tables au premier lancement, puis du compte administrateur. */

const SCHEMA_VERSION = 1;

function schema_statements(): array
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS meta (k VARCHAR(50) PRIMARY KEY, v VARCHAR(190) NOT NULL) $engine",
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL,
            must_change_password TINYINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            created_at VARCHAR(30) NOT NULL
        ) $engine",
        "CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            expires_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            k CHAR(64) PRIMARY KEY,
            attempts INT NOT NULL,
            until_ts INT NOT NULL
        ) $engine",
        "CREATE TABLE IF NOT EXISTS titres (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL DEFAULT '',
            name VARCHAR(200) NOT NULL,
            level VARCHAR(40) NOT NULL DEFAULT '',
            description TEXT NOT NULL
        ) $engine",
        "CREATE TABLE IF NOT EXISTS courses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            titre_id INT UNSIGNED NULL,
            title VARCHAR(200) NOT NULL,
            summary TEXT NOT NULL,
            header_file_id INT UNSIGNED NULL,
            color VARCHAR(20) NOT NULL DEFAULT 'navy',
            visible TINYINT NOT NULL DEFAULT 0,
            start_date VARCHAR(30) NULL,
            end_date VARCHAR(30) NULL,
            created_by INT UNSIGNED NULL,
            created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (titre_id) REFERENCES titres(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) $engine",
        "CREATE TABLE IF NOT EXISTS enrollments (
            course_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            created_at VARCHAR(30) NOT NULL,
            PRIMARY KEY (course_id, user_id),
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS nodes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            parent_id INT UNSIGNED NULL,
            type VARCHAR(20) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            duration_minutes INT NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            visible TINYINT NOT NULL DEFAULT 1,
            available_from VARCHAR(30) NULL,
            KEY nodes_course (course_id, parent_id, position),
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_id) REFERENCES nodes(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            node_id INT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            title VARCHAR(200) NOT NULL,
            data MEDIUMTEXT NOT NULL,
            position INT NOT NULL DEFAULT 0,
            visible TINYINT NOT NULL DEFAULT 1,
            available_from VARCHAR(30) NULL,
            KEY items_node (node_id, position),
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS submissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            comment TEXT NOT NULL,
            submitted_at VARCHAR(30) NOT NULL,
            grade DOUBLE NULL,
            feedback TEXT NOT NULL,
            graded_by INT UNSIGNED NULL,
            graded_at VARCHAR(30) NULL,
            UNIQUE KEY submission_user (item_id, user_id),
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
        ) $engine",
        "CREATE TABLE IF NOT EXISTS files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stored_name CHAR(40) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime VARCHAR(150) NOT NULL,
            size BIGINT NOT NULL,
            owner_id INT UNSIGNED NULL,
            course_id INT UNSIGNED NULL,
            item_id INT UNSIGNED NULL,
            submission_id INT UNSIGNED NULL,
            kind VARCHAR(20) NOT NULL,
            created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS completions (
            item_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            completed_at VARCHAR(30) NOT NULL,
            PRIMARY KEY (item_id, user_id),
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS ai_conversations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            busy_until INT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS ai_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NOT NULL,
            created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS ai_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            name VARCHAR(255) NOT NULL,
            mime VARCHAR(150) NOT NULL,
            size BIGINT NOT NULL,
            stored_name CHAR(40) NOT NULL,
            message_id INT UNSIGNED NULL,
            FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
        ) $engine",
        "CREATE TABLE IF NOT EXISTS ai_proposals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL,
            message_id INT UNSIGNED NULL,
            data MEDIUMTEXT NOT NULL,
            course_id INT UNSIGNED NULL,
            created_at VARCHAR(30) NOT NULL,
            FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES ai_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
        ) $engine",
    ];
}

/** Vérifie que la base est à jour ; crée les tables et le compte administrateur au premier lancement. */
function ensure_schema(): void
{
    try {
        $version = (int) val("SELECT v FROM meta WHERE k = 'schema_version'");
    } catch (PDOException) {
        $version = 0;
    }
    if ($version >= SCHEMA_VERSION) {
        return;
    }
    foreach (schema_statements() as $sql) {
        db()->exec($sql);
    }
    if (!val("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")) {
        $password = (string) config('admin_password', '');
        if (text_len($password) < 10) {
            throw new HttpError(500, 'Dans config.php, renseignez « admin_password » (10 caractères au minimum) pour créer le compte administrateur.');
        }
        q("INSERT IGNORE INTO users (email, password_hash, first_name, last_name, role, must_change_password, created_at)
           VALUES (?, ?, 'Administration', 'AFPAR', 'admin', 1, ?)",
            [mb_strtolower((string) config('admin_email', 'admin@afpar.re')), password_hash($password, PASSWORD_DEFAULT), now_iso()]);
    }
    q("REPLACE INTO meta (k, v) VALUES ('schema_version', ?)", [(string) SCHEMA_VERSION]);
}
