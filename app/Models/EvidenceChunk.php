<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceChunk extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'session_id',
        'sequence_number',
        'storage_path',
        'byte_size',
        'chunk_hash',
        'cumulative_hash',
        'latitude',
        'longitude',
        'accuracy_meters',
        'captured_at',
        'ai_threat_indicators',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'byte_size' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'decimal:2',
            'captured_at' => 'datetime',
            'ai_threat_indicators' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EvidenceSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(EvidenceSession::class, 'session_id');
    }
}
