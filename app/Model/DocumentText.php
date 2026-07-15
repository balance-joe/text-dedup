<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\Relations\BelongsTo;

/** 与 DocumentFingerprint 一对一的归一化文本表。 */
final class DocumentText extends Model
{
    protected ?string $table = 'document_text';

    protected string $primaryKey = 'doc_pk';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected string $keyType = 'int';

    protected array $casts = [
        'doc_pk' => 'integer',
    ];

    public function fingerprint(): BelongsTo
    {
        return $this->belongsTo(DocumentFingerprint::class, 'doc_pk', 'doc_pk');
    }
}
