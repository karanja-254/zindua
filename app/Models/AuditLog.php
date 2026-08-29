<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'session_id',
        'actor_ip',
        'user_agent',
        'action',
    ];

    /**
     * @return BelongsTo<EvidenceSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(EvidenceSession::class, 'session_id');
    }
}
