<?php

declare(strict_types = 1);

test('sql optimalize rule carries the New storage reuse analysis section (issue #708)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## New storage reuse analysis');
    expect($content)->toContain('Can this data be stored in an existing storage without a drastic impact on performance?');
    expect($content)->toContain('Schema::create(...)');
    expect($content)->toContain('@skills/code-review/SKILL.md');
    expect($content)->toContain('Do not flag migrations that only add a column or index to an existing table');
});

test('sql optimalize schema design recommends DATETIME instead of TIMESTAMP', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('Fitting data types: `INT`, `DECIMAL`, `VARCHAR(n)`, `DATETIME`.');
    expect($content)->not->toContain('Fitting data types: `INT`, `DECIMAL`, `VARCHAR(n)`, `TIMESTAMP`.');
});

test('sql optimalize rule carries the Strict SQL Mode section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Strict SQL Mode');
    expect($content)->toContain('STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION');
    expect($content)->toContain('Set it **server-side**');
    expect($content)->toContain('Enable it **from day one**');
});

test('sql optimalize rule carries the Naming Conventions section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Naming Conventions');
    expect($content)->toContain('Singular table names** (`post`, not `posts`)');
    expect($content)->toContain('Primary key column is always `id`');
    expect($content)->toContain('`parent_id` for a self-reference');
    expect($content)->toContain('Module prefix only once it earns its cost');
    expect($content)->toContain('`weight_grams`, `timeout_seconds`');
});

test('sql optimalize rule carries the Nullability and Defaults section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Nullability and Defaults');
    expect($content)->toContain('`meta_title` varchar(160) NOT NULL DEFAULT \'\',');
    expect($content)->toContain('`UNIQUE` treats `NULL` values as mutually distinct');
    expect($content)->toContain('`DEFAULT` is a semantic claim, not a crash guard');
});

test('sql optimalize rule carries the Date and Time Column Types section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Date and Time Column Types');
    expect($content)->toContain('`_at` suffix = a point in time');
    expect($content)->toContain('`_date` suffix = a calendar day independent of any timezone');
    expect($content)->toContain('Never use `TIMESTAMP`');
    expect($content)->toContain('year-2038 range limit');
    expect($content)->toContain('Use `DATETIME` instead');
});

test('sql optimalize rule carries the modified_at vs updated_at section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## modified_at vs updated_at');
    expect($content)->toContain('CREATE TRIGGER `page_before_update_touch_modified_at`');
    expect($content)->toContain('NEW.content COLLATE utf8mb4_0900_bin <> OLD.content COLLATE utf8mb4_0900_bin');
    expect($content)->toContain('row-version / concurrency token');
    expect($content)->toContain('NEW.modified_at <=> OLD.modified_at');
});

test('sql optimalize rule carries the Boolean Columns section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Boolean Columns');
    expect($content)->toContain('Verify it should be boolean at all');
    expect($content)->toContain('Try a positive-polarity adjective or participle');
    expect($content)->toContain('`is_` is a legitimate fallback');
    expect($content)->toContain('permissions always get `can_`');
    expect($content)->toContain('`tinyint(1) NOT NULL`');
});

test('sql optimalize rule carries the String, Text, and ENUM Types section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## String, Text, and ENUM Types');
    expect($content)->toContain('`VARCHAR(255)` is a cargo-cult default');
    expect($content)->toContain('`TEXT` is 64 KB of *bytes*, not characters');
    expect($content)->toContain('Use `MEDIUMTEXT` for HTML/article content.');
    expect($content)->toContain('`ALGORITHM=INSTANT`');
});

test('sql optimalize rule carries the Money and Decimal Types section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Money and Decimal Types');
    expect($content)->toContain('`FLOAT` / `DOUBLE` are banned for anything computed or compared for equality');
    expect($content)->toContain('deprecated since 8.0.17');
    expect($content)->toContain('`ROUND()` is half-away-from-zero');
    expect($content)->toContain('do not cast it to float');
});

test('sql optimalize rule carries the Foreign Key Actions section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Foreign Key Actions (ON DELETE / ON UPDATE)');
    expect($content)->toContain('`ON UPDATE CASCADE` as the default');
    expect($content)->toContain('`SET NULL` is the worst possible default');
    expect($content)->toContain('cascaded deletes do not fire triggers');
    expect($content)->toContain('a cascade is meaningless under soft delete');
});

test('sql optimalize rule carries the CHECK Constraints section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## CHECK Constraints');
    expect($content)->toContain('CONSTRAINT `chk_order_shipped_needs_date`');
    expect($content)->toContain('Name constraints after the rule, not the columns');
    expect($content)->toContain('cannot reference an `AUTO_INCREMENT` column');
    expect($content)->toContain('an implication `A -> B` written as `NOT A OR B`');
});

test('sql optimalize rule carries the Collation section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Collation');
    expect($content)->toContain('`utf8mb4_0900_*` family');
    expect($content)->toContain('Legacy `utf8mb4_czech_ci`, `unicode_ci` (UCA 4.0.0), `general_ci`, and `_bin`');
    expect($content)->toContain('CHECK (`lang` REGEXP \'^[a-z]{2}$\')');
});

test('sql optimalize rule carries the Charset Choice for Externally-Queried Columns section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Charset Choice for Externally-Queried Columns');
    expect($content)->toContain('ERROR 1267 Illegal mix of collations');
    expect($content)->toContain('the visitor gets a raw 500 for typing a diacritic into a URL');
    expect($content)->toContain('@rules/security/backend.md');
    expect($content)->toContain('Use error handling without revealing sensitive information.');
});

test('sql optimalize rule carries the Primary Key Sizing section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Primary Key Sizing');
    expect($content)->toContain('`INT UNSIGNED AUTO_INCREMENT` as the default primary key');
    expect($content)->toContain('`AUTO_INCREMENT` follows the maximum, not the row count');
    expect($content)->toContain('When in doubt, take `BIGINT`');
    expect($content)->toContain('or the FK cannot even be created');
});

test('sql optimalize rule carries the Index and Constraint Naming section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## Index and Constraint Naming');
    expect($content)->toContain('Always name indexes and constraints explicitly');
    expect($content)->toContain('Indexes get no prefix; constraints get one');
    expect($content)->toContain('`fk_post_blog`, `chk_post_lang_format`');
});

test('sql optimalize rule carries the When to Break These Rules section', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/sql/optimalize.mdc');

    expect($content)->toContain('## When to Break These Rules');
    expect($content)->toContain('this table has 500 million rows and cannot survive a rebuild');
    expect($content)->toContain('`SHOW CREATE TABLE` is the one piece of documentation that travels with the data');
});
