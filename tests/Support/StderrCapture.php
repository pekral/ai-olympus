<?php

declare(strict_types = 1);

namespace Tests\Support;

use Closure;
use php_user_filter;

final class StderrCapture extends php_user_filter
{

    private const FILTER_NAME = 'tests.stderr-capture';

    private static string $captured = '';

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }

    /**
     * @param \Closure(): int $run
     * @return array{int, string}
     */
    public static function run(Closure $run): array
    {
        if (!in_array(self::FILTER_NAME, stream_get_filters(), strict: true)) {
            stream_filter_register(self::FILTER_NAME, self::class);
        }

        self::$captured = '';
        $filter = stream_filter_append(STDERR, self::FILTER_NAME, STREAM_FILTER_WRITE);

        try {
            $exitCode = $run();
        } finally {
            if ($filter !== false) {
                stream_filter_remove($filter);
            }
        }

        return [$exitCode, self::$captured];
    }

}
