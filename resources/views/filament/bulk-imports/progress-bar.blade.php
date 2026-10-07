@php
    use App\Domains\BulkImport\Enums\BulkImportStatus;

    $record = $getRecord();
    $percent = $record->progressPercent();
    $color = match ($record->status) {
        BulkImportStatus::Failed => 'var(--danger-500)',
        BulkImportStatus::CompletedWithErrors => 'var(--warning-500)',
        BulkImportStatus::Completed => 'var(--success-500)',
        default => 'var(--primary-500)',
    };
@endphp

<div style="min-width: 10rem; display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0.75rem;">
    <div
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-valuenow="{{ $percent }}"
        style="flex: 1; height: 0.5rem; border-radius: 9999px; background: rgba(128, 128, 128, 0.25); overflow: hidden;"
    >
        <div style="width: {{ $percent }}%; height: 100%; background: {{ $color }}; transition: width 0.3s;"></div>
    </div>
    <span style="font-size: 0.75rem; min-width: 2.5rem; text-align: right;">{{ $percent }}%</span>
</div>
