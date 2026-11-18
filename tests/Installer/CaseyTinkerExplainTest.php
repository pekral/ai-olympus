<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function tinkerExplainReferencePath(): string
{
    return 'skills/mysql-problem-solver/references/tinker-explain.md';
}

function tinkerExplainSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function tinkerExplainReference(): string
{
    return tinkerExplainSource(tinkerExplainReferencePath());
}

/**
 * @return list<string>
 */
function tinkerExplainSnippetBlocks(): array
{
    preg_match_all('/```php\n(.*?)```/s', tinkerExplainReference(), $matches);

    return array_values($matches[1]);
}

function tinkerExplainFunction(string $name): Closure
{
    $file = (string) tempnam(sys_get_temp_dir(), 'tinker-explain');

    try {
        file_put_contents($file, "<?php\n" . tinkerExplainSnippetBlocks()[0] . "\nreturn compact('guard', 'cacheGuard', 'accept', 'isSpec', 'emit');\n");
        $functions = include $file;
    } finally {
        unlink($file);
    }

    $function = is_array($functions) ? ($functions[$name] ?? null) : null;

    if (!$function instanceof Closure) {
        throw new LogicException('The snippet defines no function ' . $name);
    }

    return $function;
}

function tinkerExplainEmitted(string $key, string|int $value): string
{
    $emit = tinkerExplainFunction('emit');
    ob_start();
    $emit($key, $value);

    return (string) ob_get_clean();
}

test('the reference is the one owner of the Tinker test and the project without Laravel keeps the current behaviour (issue #349)', function (): void {
    $reference = tinkerExplainReference();

    expect($reference)->toContain('This reference is the one owner of the Tinker test of `MODE=cr-report`')
        ->and($reference)->toContain('It applies only to MySQL and MariaDB.')
        ->and($reference)->toContain(
            'A project without Laravel, without a local database, or without Laravel Tinker keeps the plain database client path of `SKILL.md`, unchanged.',
        )
        ->and($reference)->toContain(
            '`composer.json` requires `laravel/framework` and `laravel/tinker`, the file `artisan` exists, and `vendor/autoload.php` exists.',
        )
        ->and($reference)->toContain('`casey` never installs a dependency');
});

test('the trust rule states where casey may boot the application, with the owner decision (invariant 1)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'no branch switch' => ['`casey` never switches a branch to reach this state'],
    'no pull request' => ['With no pull request there is no author whose trust the rule can read.'],
    'the authorship rule' => ['(`@rules/code-review/general.md` *Authorship trust*)'],
    'the author test' => [
        'The output `false`, a `null` association (the loader could not read it), an empty output, and a failed command are untrusted.',
    ],
    'the booting limit' => ['The procedure boots the application of the checkout it runs in.'],
    'the fallback' => ['In every other case the Tinker test does not run, and the report names the failed item'],
    'the fork limit' => ['The loader field `isCrossRepository` is `false`. A missing or `null` value counts as a fork (fail closed).'],
    'the head test' => ['`git rev-parse HEAD` equals the head SHA of the dispatch, and `git status --porcelain --untracked-files=no` prints nothing.'],
    'the loader field' => [
        '`casey` reads the loader field `authorAssociation` from the '
        . 'output of `skills/code-review-github/scripts/load-issue.sh <PR-URL>`',
    ],
    'the shared trust test' => ['decides it with `write_access` of `skills/_shared/review-trust.jq`'],
]);

test('the guard compares the driver, the environment name, and every host without connecting (invariant 2)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'connect after the proof' => ['The snippet opens the connection only after the guard returned `ok`.'],
    'every host' => ['The guard checks `host`, every entry of a host list, `read.host`, `write.host`, and the host of a URL-style `url` (DSN).'],
    'no connection' => ['The guard compares values already loaded in memory and opens no connection.'],
    'the driver' => ['1. The driver is `mysql` or `mariadb`.'],
    'the environment' => ['2. The environment name (`app()->environment()`) is `local` or `testing`.'],
]);

test('nothing from the diff runs as text and the diff reaches the snippet as data only (invariant 3)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'a closed vocabulary' => ['The snippet accepts only these methods:'],
    'a document on one line' => ['one JSON document on one line, written to `.claude/run/<source-slug>.casey-query.json`'],
    'a fixed snippet' => ['The snippet is the fixed text of *The snippet* below. `casey` pastes it verbatim and never edits it.'],
    'an outside query is static' => [
        'A query the vocabulary cannot express (a raw expression, a local scope, a relation, a join, a closure) '
        . 'is analysed statically',
    ],
    'a quoted heredoc' => ['with a quoted heredoc'],
    'no code and no value' => [
        'No code taken from the diff goes into the snippet, and no value from the diff is interpolated into PHP '
        . 'or into a shell command.',
    ],
    'the variable is never read' => ['`casey` sets the variable for the one command and never reads its value.'],
    'values are bindings' => ['Values travel as bindings and never as SQL text.'],
]);

test('the snippet carries no value of the diff and reads the document only through the environment variable (invariant 3)', function (): void {
    $snippet = implode("\n", tinkerExplainSnippetBlocks());

    expect($snippet)->toContain('getenv(\'CASEY_QUERY_SPEC\')')
        ->and($snippet)->not->toContain('eval(')
        ->and($snippet)->not->toContain('<?php')
        ->and($snippet)->not->toContain('shell_exec')
        ->and($snippet)->not->toContain('exec(')
        ->and($snippet)->not->toContain('whereRaw')
        ->and($snippet)->not->toContain('DB::raw');
});

test('the output list is closed and the command drops every other line (invariant 4)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'bindings count only' => ['the number of bindings, never a value'],
    'no exception message' => ['The snippet catches every `Throwable` and prints `casey:error=explain` without its message.'],
    'no secret' => ['No host, database name, DSN, credential, configuration value, or exception message is printed.'],
    'the cache verdict' => ['| `casey:cache=` | `ok` or `refused`: the driver check of the default cache store |'],
    'the default verdict' => ['| `casey:default=` | `ok` or `refused`: the guard verdict of the default connection |'],
    'the filter' => ['2>&1 | grep -E \'^casey:(guard|default|cache|sql|bindings|plan|error)=\''],
    'the list' => [
        'The snippet prints only these lines. Every other line of the command output is dropped unread by the `grep` '
        . 'of the command, and `casey` never quotes or relays it.',
    ],
    'the ref column' => ['The `ref` column of `EXPLAIN` is left out because it can name the database.'],
]);

test('a refused or failed guard ends in static analysis with the reason named (invariant 5)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'no message' => ['The report never quotes a message of the failure.'],
    'the fallback' => ['`casey` analyses the query statically and writes the reason into the `DB access:` line as `static analysis only — <reason>`.'],
    'the reasons' => [
        'The reason is one of: `fork pull request`, `author not trusted`, `checkout is not the reviewed head`, '
        . '`configuration is cached`, `guard refused: <the token>`, `statement not accepted`, `query outside the vocabulary`, '
        . 'or `EXPLAIN failed`.',
    ],
]);

test('the statement check is an accept rule over a closed vocabulary (invariant 6)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'accepts, never lists' => ['The rule accepts, and never lists what it refuses.'],
    'no writes' => ['A statement that changes data or schema never runs, because the accept rule refuses it before any connection exists.'],
    'plain explain' => ['The procedure runs plain `EXPLAIN` and never `EXPLAIN ANALYZE`, so it measures no latency.'],
    'the refusals' => ['A semicolon, a quote, a comment, `into`, `union`, `for update`, and every other word make the statement refused'],
    'the rule' => ['A statement is accepted when it is one statement that starts with `select` and every character belongs to a closed vocabulary'],
]);

test('Telescope never quotes a recorded statement with its bindings and quotes a project-relative path (invariant 7)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'after the guard' => ['and only after the guard printed `ok`'],
    'optional' => ['## Laravel Boost and Laravel Telescope — optional, never required'],
    'the path' => ['The report quotes the `file` of an entry relative to the project root and never an absolute path.'],
    'the statement' => ['The report never quotes the recorded statement: Telescope stores it with its bindings inlined.'],
]);

test('Boost names only the tools whose names the implementation verified, and calls only database-schema (criterion 3)', function (): void {
    $reference = tinkerExplainReference();

    expect($reference)->toContain('`casey` calls the Boost tool `database-schema`')
        ->and($reference)->toContain('without its `database` argument')
        ->and($reference)->toContain(
            'A project without Boost, and a session without that tool, read the schema from the migrations and from the plain database client.',
        )
        ->and($reference)->toContain('`casey` never calls the Boost tool `tinker`')
        ->and($reference)->toContain('or `database-query`')
        ->and($reference)->toContain('verified in the source of `laravel/boost` v2.10.4 (`src/Mcp/Tools/`)')
        ->and($reference)->not->toContain('search-docs')
        ->and($reference)->not->toContain('database-connections')
        ->and($reference)->not->toContain('last-error');
});

test('the text names what the mechanism does not cover (invariant 8)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'a copy of production data' => ['A loopback database that holds a copy of production data.'],
    'a forwarded socket' => [
        'A loopback port or a unix socket (including the default socket of `localhost`) that a tunnel or a proxy forwards to another host.',
    ],
    'a socket on line 3' => ['The guard accepts a `unix_socket` as local, and it cannot see where the socket leads'],
    'localhost' => ['The name `localhost`, which the guard accepts without resolving it.'],
    'other authors' => ['A commit on the pull request branch by an account other than the author.'],
    'runtime files' => ['Files the boot writes inside the checkout (logs, caches).'],
    'the boot' => ['Code that runs while the application boots, before the snippet.'],
    'the file cache path' => ['The path of a `file` cache store. `casey:cache=ok` proves the driver of the store, not where its configured path leads.'],
    'the local plan' => ['The plan of the local database. It reflects the local data volume and statistics'],
    'the section' => ['## What the mechanism does not cover'],
]);

test('casey and its Codex adapter describe the Tinker test the same way (criterion 6)', function (): void {
    $casey = tinkerExplainSource('agents/casey.md');
    $codex = tinkerExplainSource('codex/agents/casey.toml');
    $skill = tinkerExplainSource('skills/mysql-problem-solver/SKILL.md');

    expect($casey)->toContain('`@skills/mysql-problem-solver/references/tinker-explain.md`')
        ->and($casey)->toContain('**Tinker test (MySQL / MariaDB in a Laravel project).**')
        ->and($casey)->toContain('Run `php artisan tinker --execute` only with the fixed snippet')
        ->and($casey)->toContain('Put no value from the diff into a shell command or into PHP')
        ->and($casey)->toContain('Keep only the `casey:` lines of the output and never quote another line')
        ->and($casey)->toContain('Write the query document of the run to `.claude/run/<source-slug>.casey-query.json` and remove it when the run ends.')
        ->and($casey)->toContain('a project without them keeps the current behaviour')
        ->and($codex)->toContain(
            'tests the MySQL queries of the diff through php artisan tinker, only under the trust rule of '
                . 'mysql-problem-solver references/tinker-explain.md',
        )
        ->and($codex)->toContain('falls back to static analysis with the reason named')
        ->and($skill)->toContain('**tests the query through `php artisan tinker` in a Laravel project**')
        ->and($skill)->toContain(
            'That reference owns the trust rule (the application boots only in the checkout of a non-fork pull request whose author is trusted)',
        )
        ->and($skill)->toContain('A project without Laravel, without Laravel Tinker, or without a local database keeps the plain database client path');
});

test('the DB access line names how the facts were obtained and Telescope facts name their source (criterion 5)', function (): void {
    $template = tinkerExplainSource('skills/mysql-problem-solver/templates/cr-report.md');
    $skill = tinkerExplainSource('skills/mysql-problem-solver/SKILL.md');

    expect($template)->toContain('DB access: <the plain database client | Laravel Tinker | static analysis only — <reason>>')
        ->and($template)->toContain('with `+ Laravel Boost` or `+ Laravel Telescope` added when one of them supplied a fact')
        ->and($skill)->toContain(
            'A fact read from a Laravel Telescope entry (the recorded `time`, the `slow` flag, or the number of entries with one `hash`) names that source',
        )
        ->and($skill)->toContain('the report never quotes the recorded statement with its bindings');
});

test('the changelog records the Tinker test (criterion 8)', function (): void {
    expect(tinkerExplainSource('CHANGELOG.md'))->toContain(
        '`casey` tests the MySQL / MariaDB queries of a diff through `php artisan tinker` in a Laravel project (#349).',
    );
});

test('the snippet has two blocks and the first holds only the pure functions', function (): void {
    $blocks = tinkerExplainSnippetBlocks();

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0])->not->toContain('config(')
        ->and($blocks[0])->not->toContain('DB::')
        ->and($blocks[0])->not->toContain('getenv')
        ->and($blocks[1])->toContain('$connectionName = (new $model())->getConnectionName()')
        ->and(strpos($blocks[1], '$guard('))->toBeLessThan((int) strpos($blocks[1], '$builder = $model::query()'))
        ->and(strpos($blocks[1], '$guard('))->toBeLessThan((int) strpos($blocks[1], '->select(\'explain \''));
});

test('the snippet guards the default connection that Boost reads and refuses while Telescope records, before any query (issue #349)', function (): void {
    $run = tinkerExplainSnippetBlocks()[1];
    $query = (int) strpos($run, '$builder = $model::query()');

    expect($run)->toContain('$default = $guard((array) config(\'database.connections.\' . config(\'database.default\')), $environment);')
        ->and($run)->toContain('$emit(\'default\', $default === \'ok\' ? \'ok\' : \'refused\');')
        ->and($run)->toContain('$verdict = config(\'telescope.enabled\') ? \'refused:telescope\' : $guard(')
        ->and(strpos($run, '$emit(\'default\''))->toBeLessThan($query)
        ->and(strpos($run, 'config(\'telescope.enabled\')'))->toBeLessThan($query);
});

test(
    'the guard refuses a connection that is not a local development MySQL one, without connecting',
    function (array $connection, string $environment, string $verdict): void {
        $guard = tinkerExplainFunction('guard');
    
        expect($guard($connection, $environment))->toBe($verdict);
    },
)->with([
    'a database that adds a DSN host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app;host=db.example.com'], 'local', 'refused:dsn'],
    'a database that is not a scalar' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => ['app']], 'local', 'refused:dsn'],
    'a list-form read with a remote connection' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => [['host' => '127.0.0.1'], ['host' => 'replica.example']]],
        'local',
        'refused:host',
    ],
    'a list-form read with a remote host string' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => ['127.0.0.1', 'replica.example']],
        'local',
        'refused:host',
    ],
    'a list-form write entry port that adds a DSN host' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'write' => [['host' => '127.0.0.1', 'port' => '3306;host=db.example.com']]],
        'local',
        'refused:dsn',
    ],
    'a list-form write with a remote connection' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'write' => [['host' => ['primary.example']]]],
        'local',
        'refused:host',
    ],
    'a list-form write with a remote host string' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'write' => ['primary.example']],
        'local',
        'refused:host',
    ],
    'a list entry that is neither a connection nor a host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => [42]], 'local', 'refused:host'],
    'a list of local read connections' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => [['host' => '127.0.0.1'], ['host' => 'localhost']]],
        'local',
        'ok',
    ],
    'a list of local read host strings' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => ['127.0.0.1', '::1']], 'local', 'ok'],
    'a local connection with a port and a database' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'app'], 'local', 'ok'],
    'a local URL with a port and a database' => [['driver' => 'mysql', 'url' => 'mysql://user:secret@127.0.0.1:3306/app'], 'local', 'ok'],
    'a loopback host in the local environment' => [['driver' => 'mysql', 'host' => '127.0.0.1'], 'local', 'ok'],
    'a loopback lookalike' => [['driver' => 'mysql', 'host' => '127.0.0.2'], 'local', 'refused:host'],
    'a MariaDB loopback host in the testing environment' => [['driver' => 'mariadb', 'host' => 'LOCALHOST'], 'testing', 'ok'],
    'a missing driver' => [['host' => '127.0.0.1'], 'local', 'refused:driver'],
    'a mixed read section with a remote list entry' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => [['host' => 'replica.example'], 'host' => '127.0.0.1']],
        'local',
        'refused:host',
    ],
    'an empty host' => [['driver' => 'mysql', 'host' => ''], 'local', 'refused:host'],
    'an IPv6 loopback in a URL' => [['driver' => 'mysql', 'url' => 'mysql://user:secret@[::1]:3306/app'], 'local', 'ok'],
    'another driver' => [['driver' => 'pgsql', 'host' => '127.0.0.1'], 'local', 'refused:driver'],
    'a port that adds a DSN host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306;host=db.example.com'], 'local', 'refused:dsn'],
    'a read section database that adds a DSN host' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => ['host' => '127.0.0.1', 'database' => 'app;host=db.example.com']],
        'local',
        'refused:dsn',
    ],
    'a remote host' => [['driver' => 'mysql', 'host' => 'db.internal.example'], 'local', 'refused:host'],
    'a remote host in a list' => [['driver' => 'mysql', 'host' => ['127.0.0.1', 'db.internal.example']], 'local', 'refused:host'],
    'a remote host string as the whole read value' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => 'replica.example'], 'local', 'refused:host'],
    'a remote read host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => ['host' => ['replica.example']]], 'local', 'refused:host'],
    'a remote URL host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'url' => 'mysql://user:secret@db.example:3306/app'], 'local', 'refused:host'],
    'a remote write host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'write' => ['host' => 'primary.example']], 'local', 'refused:host'],
    'a socket that adds a DSN host' => [['driver' => 'mysql', 'unix_socket' => '/tmp/mysql.sock;host=db.example.com'], 'local', 'refused:dsn'],
    'a unix socket with no host' => [['driver' => 'mysql', 'unix_socket' => '/tmp/mysql.sock'], 'local', 'ok'],
    'a URL inside a list-form read entry' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => [['url' => 'mysql://127.0.0.1/app']]],
        'local',
        'refused:url',
    ],
    'a URL inside a read section' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'read' => ['url' => 'mysql://127.0.0.1/app']], 'local', 'refused:url'],
    'a URL path that adds a DSN host' => [['driver' => 'mysql', 'url' => 'mysql://127.0.0.1/app%3Bhost%3Ddb.example.com'], 'local', 'refused:dsn'],
    'a URL query that sets a list-form write host' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'url' => 'mysql://127.0.0.1/app?write[0][host]=db.example.com'],
        'local',
        'refused:url',
    ],
    'a URL query that sets the host' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'url' => 'mysql://127.0.0.1/app?host=db.example.com'],
        'local',
        'refused:url',
    ],
    'a URL query that sets the read host' => [
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'url' => 'mysql://127.0.0.1/app?read[host]=db.example.com'],
        'local',
        'refused:url',
    ],
    'a URL that is not a string' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'url' => ['mysql://127.0.0.1/app']], 'local', 'refused:url'],
    'a URL with an empty query' => [['driver' => 'mysql', 'url' => 'mysql://127.0.0.1/app?'], 'local', 'refused:url'],
    'a URL with another scheme' => [['driver' => 'mysql', 'url' => 'pgsql://127.0.0.1/app'], 'local', 'refused:url'],
    'a URL with no host' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'url' => 'not a url:///'], 'local', 'refused:url'],
    'no host and no socket' => [['driver' => 'mysql'], 'local', 'refused:no-host'],
    'the production environment' => [['driver' => 'mysql', 'host' => '127.0.0.1'], 'production', 'refused:environment'],
    'the staging environment' => [['driver' => 'mysql', 'host' => '127.0.0.1'], 'staging', 'refused:environment'],
]);

test(
    'the accept rule takes one select statement from the closed vocabulary and refuses everything else',
    function (string $statement, bool $accepted): void {
        $accept = tinkerExplainFunction('accept');
    
        expect($accept($statement))->toBe($accepted);
    },
)->with([
    'a block comment' => ['select * from `users` /* x */', false],
    'a builder statement' => ['select * from `users` where `id` = ? and `deleted_at` is null order by `id` desc limit 10', true],
    'a delete' => ['delete from `users`', false],
    'a distinct projection' => ['select distinct `id` from `orders` where `id` in (?, ?) and `a` between ? and ? limit 5', true],
    'a double-quoted literal' => ['select * from `users` where `name` = "x"', false],
    'a function outside the vocabulary' => ['select sleep(5)', false],
    'a glued keyword' => ['selectfrom `users`', false],
    'a hash comment' => ['select * from `users` # trailing', false],
    'a leading space' => [' select * from `users`', false],
    'a line comment' => ['select * from `users` -- trailing', false],
    'a lock' => ['select * from `users` for update', false],
    'an aggregate' => ['select count(*) from `orders` where `status` = ?', true],
    'an empty statement' => ['', false],
    'an exists subquery' => ['select * from `orders` where exists (select * from `items` where `items`.`order_id` = `orders`.`id`)', true],
    'an outfile' => ['select * from `users` into outfile `x`', false],
    'an update' => ['update `users` set `a` = ?', false],
    'a string literal' => ['select * from `users` where `name` = \'x\'', false],
    'a union' => ['select * from `users` union select * from `admins`', false],
    'a version comment' => ['select * from `users` /*!50000 x */', false],
    'stacked statements' => ['select * from `users`; drop table `users`', false],
]);

test('the document check takes only the closed vocabulary of builder methods', function (mixed $spec, bool $valid): void {
    $isSpec = tinkerExplainFunction('isSpec');

    expect($isSpec($spec))->toBe($valid);
})->with([
    'a column with a space' => [['model' => 'App\\Order', 'operations' => [['where', ['a b', '=', 1]]]], false],
    'a column with SQL' => [['model' => 'App\\Order', 'operations' => [['orderBy', ['id; drop table x', 'asc']]]], false],
    'a list as a scalar' => [['model' => 'App\\Order', 'operations' => [['where', ['a', '=', [1]]]]], false],
    'a method outside the vocabulary' => [['model' => 'App\\Order', 'operations' => [['whereRaw', ['1 = 1']]]], false],
    'a missing model' => [['operations' => []], false],
    'a model name with a call' => [['model' => 'App\\Order::boot()', 'operations' => []], false],
    'a model name with PHP' => [['model' => 'App\\Order;system(1)', 'operations' => []], false],
    'a negative limit' => [['model' => 'App\\Order', 'operations' => [['limit', [-1]]]], false],
    'an operator outside the list' => [['model' => 'App\\Order', 'operations' => [['where', ['a', 'is', 1]]]], false],
    'a where and a limit' => [['model' => 'App\\Models\\Order', 'operations' => [['where', ['status', '=', 'paid']], ['limit', [5]]]], true],
    'every method of the vocabulary' => [
        ['model' => '\\App\\Order', 'operations' => [
            ['select', ['id', 'orders.status']], ['distinct', []], ['where', ['a', 'like', 'x%']], ['orWhere', ['b', '<>', null]],
            ['whereIn', ['id', [1, 2]]], ['whereNotIn', ['id', ['a']]], ['whereNull', ['c']], ['whereNotNull', ['d']],
            ['whereBetween', ['e', [1, 2]]], ['orderBy', ['f', 'desc']], ['limit', [1]], ['offset', [0]],
        ],
        ], true,
    ],
    'no document' => [null, false],
    'too many operations' => [['model' => 'App\\Order', 'operations' => array_fill(0, 21, ['distinct', []])], false],
]);

test('the emit function prints only lines of the closed list', function (string $key, string|int $value, string $printed): void {
    expect(tinkerExplainEmitted($key, $value))->toBe($printed);
})->with([
    'a cache value outside the tokens' => ['cache', 'redis', ''],
    'a default value outside the tokens' => ['default', 'mysql', ''],
    'a guard value outside the tokens' => ['guard', 'db.internal.example', ''],
    'a key outside the list' => ['host', '127.0.0.1', ''],
    'an error token' => ['error', 'statement', 'casey:error=statement' . PHP_EOL],
    'an error value outside the tokens' => ['error', 'SQLSTATE[HY000] Access denied for user', ''],
    'a refusal token' => ['guard', 'refused:host', 'casey:guard=refused:host' . PHP_EOL],
    'the bindings count' => ['bindings', 2, 'casey:bindings=2' . PHP_EOL],
    'the cache refusal' => ['cache', 'refused', 'casey:cache=refused' . PHP_EOL],
    'the cache verdict' => ['cache', 'ok', 'casey:cache=ok' . PHP_EOL],
    'the default verdict' => ['default', 'refused', 'casey:default=refused' . PHP_EOL],
    'the DSN refusal' => ['guard', 'refused:dsn', 'casey:guard=refused:dsn' . PHP_EOL],
    'the guard verdict' => ['guard', 'ok', 'casey:guard=ok' . PHP_EOL],
    'the statement' => ['sql', 'select * from `users` where `id` = ?', 'casey:sql=select * from `users` where `id` = ?' . PHP_EOL],
    'the Telescope refusal' => ['guard', 'refused:telescope', 'casey:guard=refused:telescope' . PHP_EOL],
]);

test('a failed precondition 1, 2, or 3 keeps the plain database client path, never static analysis (criterion 4)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'the fallback section' => [
        'A failed precondition 1, 2, or 3 is not such a failure. A run without a pull request, a project without Laravel or Laravel Tinker, '
        . 'and a project whose dependencies are not installed keep the plain database client path of `SKILL.md`, unchanged.',
    ],
    'the plain path' => [
        'When item 1, 2, or 3 fails, the Tinker test does not apply. The report uses the plain database client path of `SKILL.md`, '
        . 'and its `DB access:` line names that path.',
    ],
    'the static path' => ['When item 4 or 5 fails, the report goes on with static analysis and names the item as the reason in its `DB access:` line.'],
]);

test('the static-analysis reasons name no precondition that keeps the plain database client path (criterion 4)', function (): void {
    $reference = tinkerExplainReference();

    expect($reference)->not->toContain('`no pull request`')
        ->and($reference)->not->toContain('`not a Laravel project`')
        ->and($reference)->not->toContain('`no Laravel Tinker`')
        ->and($reference)->not->toContain('`dependencies not installed`')
        ->and($reference)->not->toContain('The first item that fails ends the procedure, and the report goes on with static analysis')
        ->and(tinkerExplainSource('agents/casey.md'))->toContain(
            'A run without a pull request and a project without Laravel or Laravel Tinker keep the plain database client path.',
        );
});

test('the trust rule reads the author association from the loader and decides it with the shared trust test (reuse)', function (): void {
    $reference = tinkerExplainReference();
    $casey = tinkerExplainSource('agents/casey.md');

    expect($reference)->not->toContain('gh api')
        ->and($reference)->not->toContain('`OWNER`, `MEMBER`, and `COLLABORATOR`')
        ->and($reference)->toContain('`jq -L skills/_shared \'include "review-trust"; .authorAssociation | write_access\'`')
        ->and($casey)->not->toContain('gh api repos/')
        ->and($casey)->toContain('with `write_access` of `skills/_shared/review-trust.jq`, and make no other read for it.');
});

test('the trust test of the reference trusts only an account with write access', function (mixed $association, string $verdict): void {
    $process = new Process(
        ['jq', '-L', dirname(__DIR__, 2) . '/skills/_shared', 'include "review-trust"; .authorAssociation | write_access'],
        input: (string) json_encode(['authorAssociation' => $association]),
    );
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe($verdict);
})->with([
    'a collaborator' => ['COLLABORATOR', 'true'],
    'a contributor' => ['CONTRIBUTOR', 'false'],
    'a member' => ['MEMBER', 'true'],
    'an association the loader could not read' => [null, 'false'],
    'an outsider' => ['NONE', 'false'],
    'the owner' => ['OWNER', 'true'],
]);

test('Boost reads the schema only after the default connection passed the guard (Critical 2)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'the condition' => [
        'only after a run of this procedure printed `casey:guard=ok`, `casey:default=ok`, '
        . 'and `casey:cache=ok` in this run, and without its `database` argument.',
    ],
    'the default guard' => ['The snippet runs the same guard on the default connection (`config(\'database.default\')`)'],
    'the reason' => [
        'Without that argument Boost reads the default connection, so `casey:default=ok` is the proof for the connection Boost reads; '
        . '`casey:guard=ok` alone proves only the connection of the model.',
    ],
    'the server limit' => ['The configuration the Laravel Boost server loaded when it started.'],
]);

test('the guard checks both forms of read and write hosts (Critical 1)', function (): void {
    expect(tinkerExplainReference())->toContain(
        '4. `read` and `write` are checked in both forms Laravel accepts: one section, and a list from which Laravel picks one entry at random.',
    )->and(tinkerExplainReference())->toContain(
        'An entry that is neither a connection array nor a host string is refused, and so is a `url` inside a `read` or `write` section.',
    );
});

test('the Tinker run records no query in Telescope and names the write that remains (Telescope)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'the cached configuration' => [
        '5. The configuration is not cached: the file `bootstrap/cache/config.php` '
        . 'does not exist. `casey` checks only that the file exists and never reads it.',
    ],
    'the command' => ['TELESCOPE_ENABLED=false CASEY_QUERY_SPEC=<absolute path of the document> php artisan tinker --execute='],
    'the guard' => ['The snippet also refuses with `refused:telescope` when `config(\'telescope.enabled\')` is true after the boot.'],
    'the remaining update' => [
        '`Telescope::flushEntries()` drops the queued entries, not an entry update (`Telescope::recordUpdate()`, which the job watchers use).',
    ],
    'the statement' => [
        'Laravel Telescope stores no entry of the Tinker run: the command switches it off, the snippet first stops its recording '
        . 'and drops the entries the boot queued (`Telescope::stopRecording()` and `Telescope::flushEntries()`, when the class exists)',
    ],
]);

test('the snippet stops Telescope recording and drops the queued entries before it reads the document (Telescope)', function (): void {
    $run = tinkerExplainSnippetBlocks()[1];
    $stop = (int) strpos($run, '\\Laravel\\Telescope\\Telescope::stopRecording();');
    $flush = (int) strpos($run, '\\Laravel\\Telescope\\Telescope::flushEntries();');

    expect($run)->toContain('if (class_exists(\\Laravel\\Telescope\\Telescope::class)) {')
        ->and($stop)->toBeGreaterThan(0)
        ->and($flush)->toBeGreaterThan($stop)
        ->and($flush)->toBeLessThan((int) strpos($run, 'getenv(\'CASEY_QUERY_SPEC\')'));
});

test('Boost runs only without Telescope and only when the default cache store is local (Telescope and Boost)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'the cache check' => [
        'It prints `casey:cache=ok` when the driver of that store is `array` or `file`, and `casey:cache=refused` '
        . 'for every other driver, a missing store, and a store with no driver.',
    ],
    'the cache write' => [
        'The tool keeps the schema it read for 20 seconds in the default cache store of the application (`Cache::remember`). '
        . 'That is a write, so `casey:cache=ok` is the proof for the store Boost writes to.',
    ],
    'the process' => ['Boost runs every tool in its own `php artisan boost:execute-tool` process.'],
    'the reason' => ['A project that requires `laravel/telescope` therefore reads the schema without Boost.'],
    'the refused stores' => [
        'A `redis`, `memcached`, `dynamodb`, or `database` store writes over a connection the guard never checked, '
        . 'so a project with such a default store reads the schema without Boost.',
    ],
    'without Telescope' => ['only when `composer.json` requires `laravel/telescope` in neither `require` nor `require-dev`'],
]);

test('the guard refuses a URL query string, a foreign scheme, and a DSN part that adds a key (Critical, round 2)', function (string $text): void {
    expect(tinkerExplainReference())->toContain($text);
})->with([
    'the DSN parts' => [
        '`database`, `port`, and `unix_socket` of the connection and of every `read` or `write` entry, and the decoded database path of a `url`, '
        . 'are refused with `refused:dsn` when they contain `;` or `=`, or when they are not a scalar.',
    ],
    'the last host' => ['PDO takes the last `host=` of a DSN, so such a value moves the connection to another host.'],
    'the query string' => [
        'A `url` that carries a query string is refused with `refused:url`, because Laravel merges its parameters '
        . '(`host`, `read[host]`, `write[0][host]`) over the host of the URL and over the connection.',
    ],
    'the scheme' => ['A `url` whose scheme is not `mysql` or `mariadb`, and a `url` that is not a string, are refused too'],
    'the token' => ['`url`, `dsn`, or `telescope` |'],
]);

test('the snippet checks the driver of the default cache store that Boost writes to, before any query (Critical, round 3)', function (): void {
    $run = tinkerExplainSnippetBlocks()[1];

    expect($run)->toContain('$emit(\'cache\', $cacheGuard(config(\'cache.stores.\' . config(\'cache.default\'))));')
        ->and(strpos($run, '$emit(\'cache\''))->toBeLessThan((int) strpos($run, '$builder = $model::query()'));
});

test('the cache check accepts only the array and the file driver of the default cache store', function (mixed $store, string $verdict): void {
    $cacheGuard = tinkerExplainFunction('cacheGuard');

    expect($cacheGuard($store))->toBe($verdict);
})->with([
    'a database store' => [['driver' => 'database', 'connection' => 'mysql', 'table' => 'cache'], 'refused'],
    'a driver that is not a string' => [['driver' => ['file']], 'refused'],
    'a dynamodb store' => [['driver' => 'dynamodb', 'table' => 'cache'], 'refused'],
    'a file store' => [['driver' => 'file', 'path' => '/app/storage/framework/cache/data'], 'ok'],
    'a memcached store' => [['driver' => 'memcached', 'servers' => [['host' => '127.0.0.1']]], 'refused'],
    'a missing store' => [null, 'refused'],
    'an array store' => [['driver' => 'array', 'serialize' => false], 'ok'],
    'an empty driver' => [['driver' => ''], 'refused'],
    'a redis store' => [['driver' => 'redis', 'connection' => 'cache'], 'refused'],
    'a store with no driver' => [['path' => '/tmp/cache'], 'refused'],
]);
