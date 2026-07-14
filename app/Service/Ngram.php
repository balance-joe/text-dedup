<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class Ngram
{
    /**
     * 按 UTF-8 字符生成相互重叠的 n-gram，不去重并保留原始顺序。
     *
     * @return list<string>
     */
    public static function items(string $text, int $size): array
    {
        if ($size < 1) {
            throw new InvalidArgumentException('N-gram 长度必须大于零。');
        }
        if ($text === '') {
            return [];
        }

        preg_match_all('/./us', $text, $matches);
        $characters = $matches[0] ?? [];
        $length = count($characters);
        if ($length <= $size) {
            return [$text];
        }

        $grams = [];
        $last = $length - $size;
        if ($size === 5) {
            // 生产链路固定使用 5-gram，直接拼接可避免循环中反复创建 array_slice。
            for ($index = 0; $index <= $last; ++$index) {
                $grams[] = $characters[$index]
                    . $characters[$index + 1]
                    . $characters[$index + 2]
                    . $characters[$index + 3]
                    . $characters[$index + 4];
            }
            return $grams;
        }

        for ($index = 0; $index <= $last; ++$index) {
            $grams[] = implode('', array_slice($characters, $index, $size));
        }
        return $grams;
    }
}
