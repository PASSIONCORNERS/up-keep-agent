<?php

namespace Fixtures\Vendor;

use RuntimeException;

/**
 * Stands in for a Composer package that throws on the app's behalf.
 */
class Library
{
    public function explode(): void
    {
        throw new RuntimeException('vendor boom');
    }
}
