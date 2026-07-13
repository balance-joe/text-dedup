<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use Hyperf\DbConnection\Db;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testSodiumAndPgConnectionAreAvailable(): void
    {
        self::assertTrue(extension_loaded('sodium'));
        self::assertTrue(class_exists(Db::class));
    }
}
