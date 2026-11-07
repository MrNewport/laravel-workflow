<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Support;

/** Marks only the package's expected unique writes, excluding host callback constraints. */
final class WriteCollision
{
    public bool $expected = false;
}
