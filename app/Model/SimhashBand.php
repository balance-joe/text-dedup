<?php

declare(strict_types=1);

namespace App\Model;

/** 正文 SimHash LSH 倒排父表；仅用于查询和批量插入，不按单行更新。 */
final class SimhashBand extends Model
{
    protected ?string $table = 'simhash_band';

    protected string $primaryKey = 'doc_pk';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $casts = [
        'band_index' => 'integer',
        'band_value' => 'integer',
        'doc_pk' => 'integer',
        'created_at' => 'datetime',
    ];
}
