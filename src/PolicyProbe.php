<?php

declare(strict_types=1);

namespace Sentinel;

/**
 * TEMPORARY probe for verifying the Semgrep Block policy fires in CI.
 *
 * This file is deliberately vulnerable (unsanitised $_GET into exec()).
 * It exists only to confirm that a Critical/High Code finding fails the
 * Semgrep gate. THIS BRANCH MUST NEVER BE MERGED and will be deleted.
 */
final class PolicyProbe
{
    public function run(): void
    {
        $cmd = (string) ($_GET['cmd'] ?? '');
        exec($cmd);
    }
}
