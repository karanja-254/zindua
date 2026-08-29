<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProofVaultSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function test_tamper_evident_hash_chain_verification_succeeds(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = $this->seedChainedSession(5);

        $response = $this->getJson('/api/v1/evidence/'.$session->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('status', 'VERIFIED')
            ->assertJsonPath('chain_intact', true)
            ->assertJsonPath('chunks_verified', 5);
    }

    public function test_tamper_detection_triggers_422_when_intermediate_chunk_is_corrupted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = $this->seedChainedSession(5);

        EvidenceChunk::query()
            ->where('session_id', $session->id)
            ->where('sequence_number', 3)
            ->update(['chunk_hash' => hash('sha256', 'tampered-payload')]);

        $response = $this->getJson('/api/v1/evidence/'.$session->id.'/verify');

        $response->assertStatus(422)
            ->assertJsonPath('status', 'TAMPER_DETECTED')
            ->assertJsonPath('chain_intact', false)
            ->assertJsonPath('tampered_at', 3);
    }

    public function test_worm_policy_rejects_put_patch_delete_requests(): void
    {
        $session = $this->seedChainedSession(1);
        $url = '/api/v1/evidence/'.$session->id;
        $message = 'WORM Policy: Evidence records cannot be modified or destroyed.';

        $this->putJson($url, ['status' => 'active'])
            ->assertForbidden()
            ->assertJsonPath('error', $message);

        $this->patchJson($url, ['status' => 'active'])
            ->assertForbidden()
            ->assertJsonPath('error', $message);

        $this->deleteJson($url)
            ->assertForbidden()
            ->assertJsonPath('error', $message);
    }

    private function seedChainedSession(int $count): EvidenceSession
    {
        $session = EvidenceSession::create([
            'status' => 'finalized',
            'risk_level' => 'unassessed',
            'started_at' => now()->subMinutes(5),
            'finalized_at' => now(),
        ]);

        $previous = self::GENESIS;

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $chunkHash = hash('sha256', $session->id.':'.$sequence);
            $cumulative = hash('sha256', $previous.$chunkHash);

            EvidenceChunk::create([
                'session_id' => $session->id,
                'sequence_number' => $sequence,
                'storage_path' => sprintf('evidence/%s/chunks/%010d.bin', $session->id, $sequence),
                'byte_size' => 1024 * $sequence,
                'chunk_hash' => $chunkHash,
                'cumulative_hash' => $cumulative,
                'latitude' => -1.2921,
                'longitude' => 36.8219,
                'accuracy_meters' => 8.0,
                'captured_at' => now()->subMinutes(5)->addSeconds($sequence * 3),
            ]);

            $previous = $cumulative;
        }

        $session->forceFill(['chain_hash' => $previous])->save();

        return $session->fresh();
    }
}
