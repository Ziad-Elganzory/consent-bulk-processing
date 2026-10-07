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

    /**
     * How far the import has got, from 0 to 100. Splitting the file takes the first 10%,
     * validating the chunks the next 80% and building the result the rest.
     */
    public function progressPercent(): int
    {
        return match ($this->status) {
            BulkImportStatus::AwaitingUpload, BulkImportStatus::Queued => 0,
            BulkImportStatus::Parsing => 5,
            BulkImportStatus::Validating => 10 + (int) round(80 * $this->finishedChunkRatio()),
            BulkImportStatus::Assembling => 95,
            BulkImportStatus::Completed, BulkImportStatus::CompletedWithErrors, BulkImportStatus::Failed => 100,
        };
    }

    /**
     * Uses the chunks_count and finished_chunks_count a list query loaded, and counts
     * the chunks itself when they were not loaded.
     */
    public function chunkTotal(): int
    {
        return (int) ($this->chunks_count ?? $this->chunks()->count());
    }

    public function chunksFinished(): int
    {
        return (int) ($this->finished_chunks_count ?? $this->chunks()
            ->whereIn('status', BulkImportChunk::finishedStatuses())
            ->count());
    }

    private function finishedChunkRatio(): float
    {
        $total = $this->chunkTotal();

        return $total === 0 ? 0.0 : $this->chunksFinished() / $total;
    }

    protected static function newFactory(): Factory
    {
        return BulkImportFactory::new();
    }
}
