<?php

namespace App\Domains\BulkImport\Models;

use App\Domains\BulkImport\Enums\BulkImportChunkStatus;
use Database\Factories\Domains\BulkImport\Models\BulkImportChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bulk_import_id',
    'sequence',
    'status',
    'valid_rows',
    'invalid_rows',
    'source_object_key',
    'error_object_key',
    'failure_message',
])]
class BulkImportChunk extends Model
{
    /** @use HasFactory<BulkImportChunkFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = [
        'status' => BulkImportChunkStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BulkImportChunkStatus::class,
            'sequence' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
        ];
    }

    /**
     * The statuses a chunk ends in, as stored values.
     *
     * @return list<string>
     */
    public static function finishedStatuses(): array
    {
        return [BulkImportChunkStatus::Completed->value, BulkImportChunkStatus::Failed->value];
    }

    public function bulkImport(): BelongsTo
    {
        return $this->belongsTo(BulkImport::class);
    }

    protected static function newFactory(): Factory
    {
        return BulkImportChunkFactory::new();
    }
}
