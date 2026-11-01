<?php

namespace Fixtures\App;

use Fixtures\Vendor\Library;
use RuntimeException;

/**
 * Stands in for the host app's own code (tests/Fixtures is the fake base path).
 */
class Thrower
{
    /**
     * Site 0 fails inside vendor code; sites 1 and 2 throw here, from different lines.
     */
    public function run(int $site): void
    {
        if ($site === 0) {
            (new Library)->explode();
        }

        if ($site === 1) {
            throw new RuntimeException('app boom');
        }

        throw new RuntimeException('app boom');
    }

    public function inClosure(): void
    {
        (function (): void {
            throw new RuntimeException('closure boom');
        })();
    }

    public function deep(int $depth): void
    {
        if ($depth === 0) {
            throw new RuntimeException(str_repeat('x', 5000));
        }

        $this->deep($depth - 1);
    }
}
