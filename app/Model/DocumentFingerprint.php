<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Relations\HasOne;

/**
 * 文档指纹热表。
 *
 * 哈希列保持 PostgreSQL bytea 的二进制字符串形式；十六进制转换应在
 * Service 层完成，避免模型悄悄改变查询参数的字节语义。
 */
final class DocumentFingerprint extends Model
{
    protected ?string $table = 'document_fingerprint';

    protected string $primaryKey = 'doc_pk';

    public bool $incrementing = true;

    public bool $timestamps = false;

    protected string $keyType = 'int';

    protected array $casts = [
        'doc_pk' => 'integer',
        'simhash_hi' => 'integer',
        'simhash_lo' => 'integer',
        'title_simhash_hi' => 'integer',
        'title_simhash_lo' => 'integer',
        'low_information' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function text(): HasOne
    {
        return $this->hasOne(DocumentText::class, 'doc_pk', 'doc_pk');
    }
}
