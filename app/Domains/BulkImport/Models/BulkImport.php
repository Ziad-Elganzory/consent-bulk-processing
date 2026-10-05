<?php

namespace App\Domains\BulkImport\Models;

use App\Domains\BulkImport\Enums\BulkImportStatus;
use App\Models\User;
use Database\Factories\Domains\BulkImport\Models\BulkImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'status',
    'original_filename',
    'source_object_key',
    'source_size_bytes',
    'output_object_key',
    'error_object_key',
    'failure_message',
    'completed_at',
])]
class BulkImport extends Model
{
    /** @use HasFactory<BulkImportFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = [
        'status' => BulkImportStatus::AwaitingUpload->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BulkImportStatus::class,
            'source_size_bytes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(BulkImportChunk::class);
    }

    protected static function newFactory(): Factory
    {
        return BulkImportFactory::new();
    }
}
