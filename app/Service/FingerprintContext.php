<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\UInt64;
use InvalidArgumentException;

/**
 * 批处理和在线请求共用的、与框架无关的指纹计算结果。
 *
 * 该对象不会访问容器、数据库或 Swoole 协程上下文，因此可以直接在普通 PHP CLI
 * 中使用 Python 导出的兼容基准进行验证，确认后再接入 Hyperf/Swoole。
 */
final class FingerprintContext
{
    public function __construct(
        public readonly array $source,
        public readonly string $scope,
        public readonly string $text,
        public readonly string $normalizedTitle,
        public readonly string $normalizedContent,
        public readonly string $rawHash,
        public readonly ?string $contentHash,
        public readonly ?string $titleHash,
        public readonly ?string $exactHash,
        public readonly int $textLength,
        public readonly bool $lowInformation,
        public readonly string $simhashValue,
        public readonly string $simhashHex,
        public readonly int $simhashHiPgBigint,
        public readonly int $simhashLoPgBigint,
        /** @var list<array{int, string}> */
        public readonly array $simhashBands,
        /** @var list<string> uint64 的十进制字符串列表 */
        public readonly array $minhashSignature,
        /** @var list<array{int, string}> */
        public readonly array $minhashBands,
    ) {
    }

    public static function fromSource(array $source, string $scope = 'content', ?TextNormalizer $normalizer = null): self
    {
        if (!in_array($scope, ['content', 'title'], true)) {
            throw new InvalidArgumentException("不支持的指纹计算范围：{$scope}");
        }

        $normalizer ??= new TextNormalizer();
        [$normalizedTitle, $normalizedContent] = $normalizer->normalizedTitleAndContent($source);

        $contentHash = $normalizedContent === '' ? null : md5($normalizedContent);
        $titleHash = $normalizedTitle === '' ? null : md5($normalizedTitle);
        // 正文作用域在正文为空时回退到标题；标题作用域永远只使用标题。
        $text = $scope === 'content' ? ($normalizedContent ?: $normalizedTitle) : $normalizedTitle;
        $exactHash = $scope === 'content' ? ($contentHash ?: $titleHash) : $titleHash;
        $simhashNgram = DedupeParameters::simhashNgram();
        $minhashNgram = DedupeParameters::minhashNgram();
        $simhashGrams = Ngram::items($text, $simhashNgram);
        $minhashGrams = $minhashNgram === $simhashNgram ? $simhashGrams : Ngram::items($text, $minhashNgram);
        $simhashValue = SimHash::value($text, $simhashNgram, $simhashGrams);
        $minhashSignature = MinHash::signature($text, $minhashGrams);

        return new self(
            source: $source,
            scope: $scope,
            text: $text,
            normalizedTitle: $normalizedTitle,
            normalizedContent: $normalizedContent,
            rawHash: md5($normalizer->rawTextForHash($source)),
            contentHash: $contentHash,
            titleHash: $titleHash,
            exactHash: $exactHash,
            textLength: mb_strlen($text, 'UTF-8'),
            lowInformation: self::isLowInformation($text),
            simhashValue: $simhashValue,
            simhashHex: SimHash::hex($simhashValue),
            simhashHiPgBigint: UInt64::toSignedInt64(substr($simhashValue, 0, 8)),
            simhashLoPgBigint: UInt64::toSignedInt64(substr($simhashValue, 8, 8)),
            simhashBands: SimHash::bandItems($simhashValue),
            minhashSignature: MinHash::decimalSignature($minhashSignature),
            minhashBands: MinHash::bandItems($minhashSignature),
        );
    }

    /** 复刻 Python dedupe_service.text.is_low_information 的低信息文本判定规则。 */
    public static function isLowInformation(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        preg_match_all('/\[[^\[\]]{1,' . DedupeParameters::bracketTokenMaxLength() . '}\]/u', $text, $tokenMatches);
        $tokenChars = array_sum(array_map(static fn (string $token): int => mb_strlen($token, 'UTF-8'), $tokenMatches[0]));
        preg_match_all('/[\p{L}\p{N}]/u', $text, $alphanumericMatches);
        $chineseOrAlphanumeric = count($alphanumericMatches[0]);
        $length = mb_strlen($text, 'UTF-8');

        return $length < DedupeParameters::lowInformationMinLength()
            || ($tokenMatches[0] !== [] && $tokenChars / max($length, 1) >= DedupeParameters::lowInformationTokenRatio())
            || $chineseOrAlphanumeric < DedupeParameters::lowInformationMinAlphanumeric();
    }
}
