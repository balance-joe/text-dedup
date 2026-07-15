<?php

declare(strict_types=1);

namespace App\Model;

/** 正文 MinHash LSH 倒排父表；band_value 使用 PostgreSQL 有符号 BIGINT 位模式。 */
final class MinhashBand extends Model
{
    protected ?string $table = 'minhash_band';

    protected string $primaryKey = 'doc_pk';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $casts = [
        'band_index' => 'integer',
        'band_value' => 'integer',
        'doc_pk' => 'integer',
    ];
}
