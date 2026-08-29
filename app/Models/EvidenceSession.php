<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceSession extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'risk_level',
        'chain_hash',
        'started_at',
        'finalized_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * Human-readable ProofVault evidence identifier (PV-YYYYMMDD-XXXX).
     */
    public function evidenceId(): string
    {
        $date = ($this->started_at ?? $this->created_at)?->timezone('Africa/Nairobi')->format('Ymd')
            ?? now()->timezone('Africa/Nairobi')->format('Ymd');

        $suffix = strtoupper(substr(str_replace('-', '', (string) $this->id), 0, 4));

        return sprintf('PV-%s-%s', $date, $suffix);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EvidenceChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(EvidenceChunk::class, 'session_id');
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'session_id');
    }
}
