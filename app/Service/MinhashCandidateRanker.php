<?php

declare(strict_types=1);

namespace App\Service;

/** 按命中 MinHash band 数排序候选，减少正文随机回表且优先保留高置信候选。 */
final class MinhashCandidateRanker
{
    /**
     * @param list<list<int>> $bandRows 每个元素是一条有效（非热桶）band 的候选 doc_pk 列表
     * @return array{ids: list<int>, unique_before_limit: int, top_band_hits: int, truncated: bool}
     */
    public static function top(array $bandRows, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('MinHash candidate limit must be positive.');
        }

        $candidates = [];
        $firstSeen = 0;
        foreach ($bandRows as $rows) {
            // 同一个 doc_pk 在同一条 band 中即使意外重复，也只能贡献一次命中。
            $seenInBand = [];
            foreach ($rows as $docPk) {
                $docPk = (int) $docPk;
                if ($docPk < 1 || isset($seenInBand[$docPk])) {
                    continue;
                }
                $seenInBand[$docPk] = true;
                if (!isset($candidates[$docPk])) {
                    $candidates[$docPk] = ['doc_pk' => $docPk, 'band_hits' => 0, 'first_seen' => $firstSeen++];
                }
                ++$candidates[$docPk]['band_hits'];
            }
        }

        $ranked = array_values($candidates);
        usort($ranked, static function (array $left, array $right): int {
            return ($right['band_hits'] <=> $left['band_hits'])
                ?: ($left['first_seen'] <=> $right['first_seen']);
        });

        $uniqueBeforeLimit = count($ranked);
        $selected = array_slice($ranked, 0, $limit);

        return [
            'ids' => array_values(array_map(static fn (array $candidate): int => $candidate['doc_pk'], $selected)),
            'unique_before_limit' => $uniqueBeforeLimit,
            'top_band_hits' => (int) ($selected[0]['band_hits'] ?? 0),
            'truncated' => $uniqueBeforeLimit > count($selected),
        ];
    }
}
