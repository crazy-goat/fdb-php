<?php

declare(strict_types=1);

namespace CrazyGoat\FoundationDB\Future;

final class FutureVoid extends Future
{
    public function await(): mixed
    {
        if ($this->resolved) {
            return null;
        }

        $this->blockUntilReady();
        $this->resolved = true;

        return null;
    }
}
