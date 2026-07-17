<?php

declare(strict_types=1);

namespace App\Service;

use Normalizer;

final class TextNormalizer
{
    private const EMOJI = '\\x{1F600}-\\x{1F64F}\\x{1F300}-\\x{1F5FF}\\x{1F680}-\\x{1F6FF}\\x{1F1E0}-\\x{1F1FF}\\x{1F900}-\\x{1F9FF}\\x{1FA00}-\\x{1FA6F}\\x{1FA70}-\\x{1FAFF}\\x{2600}-\\x{26FF}\\x{2700}-\\x{27BF}\\x{231A}-\\x{231B}\\x{23E9}-\\x{23F3}\\x{23F8}-\\x{23FA}\\x{25AA}-\\x{25AB}\\x{25B6}-\\x{25C0}\\x{25FB}-\\x{25FE}\\x{2934}-\\x{2935}\\x{2B05}-\\x{2B07}\\x{2B1B}-\\x{2B1C}\\x{2B50}\\x{2B55}\\x{3030}\\x{303D}\\x{3297}\\x{3299}\\x{FE00}-\\x{FE0F}\\x{200D}\\x{20E3}\\x{24C2}\\x{24B6}-\\x{24E9}\\x{2460}-\\x{24FF}';

    public function normalize(mixed $value): string
    {
        if ($value === null) return '';
        $text = Normalizer::normalize((string) $value, Normalizer::FORM_KC);
        $text = str_replace(["\u{200B}", "\u{FEFF}"], '', $text === false ? '' : $text);
        if ($text === '') return '';
        $original = mb_strtolower((string) preg_replace('/\\s+/u', '', $text), 'UTF-8');
        $text = (string) preg_replace('/[' . self::EMOJI . ']+/u', '', $text);
        $text = (string) preg_replace('/\\[[^\\[\\]]{1,' . DedupeParameters::bracketTokenMaxLength() . '}\\]/u', '', $text);
        $text = (string) preg_replace('/\\d+/u', '0', $text);
        $text = str_replace('0.0', '0', $text);
        $text = (string) preg_replace('/[^\\x{4E00}-\\x{9FFF}\\x{3000}-\\x{303F}\\x{FF00}-\\x{FFEF}]/u', '', $text);
        $text = mb_strtolower((string) preg_replace('/\\s+/u', '', $text), 'UTF-8');
        return $text !== '' ? $text : $original;
    }

    public function rawTextForHash(array $source): string
    {
        return 'title' . "\x1f" . (string) ($source['title'] ?? '') . "\x1econtent\x1f" . (string) ($source['content'] ?? '');
    }

    public function normalizedTitleAndContent(array $source): array
    {
        return [$this->normalize($source['title'] ?? null), $this->normalize($source['content'] ?? null)];
    }
}
