<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\MinhashCandidateRanker;
use PHPUnit\Framework\TestCase;

final class MinhashCandidateRankerTest extends TestCase
{
    public function testRanksByDistinctBandHitsBeforeApplyingLimit(): void
    {
        $result = MinhashCandidateRanker::top([
            [10, 20, 30],
            [20, 30, 40],
            [30, 40, 50],
        ], 3);

        self::assertSame([30, 20, 40], $result['ids']);
        self::assertSame(5, $result['unique_before_limit']);
        self::assertSame(3, $result['top_band_hits']);
        self::assertTrue($result['truncated']);
    }

    public function testCountsDuplicateDocOnlyOncePerBandAndKeepsStableTieOrder(): void
    {
        $result = MinhashCandidateRanker::top([
            [7, 7, 8],
            [8, 7, 9],
        ], 10);

        self::assertSame([7, 8, 9], $result['ids']);
        self::assertSame(2, $result['top_band_hits']);
        self::assertFalse($result['truncated']);
    }
}
