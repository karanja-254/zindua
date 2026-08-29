<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Models\User;
use App\Services\EvidenceStorageService;
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

        $session = $this->seedChainedSession(5, $user);

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

        $session = $this->seedChainedSession(5, $user);

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

    public function test_missing_stored_object_is_reported_as_tamper(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $session = $this->seedChainedSession(1, $user, writeFiles: false);

        $this->getJson('/api/v1/evidence/'.$session->id.'/verify')
            ->assertStatus(422)
            ->assertJsonPath('status', 'TAMPER_DETECTED');
    }

    public function test_worm_policy_rejects_put_patch_delete_requests(): void
    {
        $session = $this->seedChainedSession(1, User::factory()->create());
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

    public function test_unauthenticated_chunk_upload_is_rejected(): void
    {
        $session = $this->seedChainedSession(1, User::factory()->create(), status: 'active');

        $this->call('POST', '/api/v1/evidence/'.$session->id.'/chunk', [], [], [], [
            'CONTENT_TYPE' => 'video/webm',
            'HTTP_ACCEPT' => 'application/json',
        ], 'bytes')
            ->assertUnauthorized();
    }

    public function test_investigator_cannot_read_another_users_session(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $session = $this->seedChainedSession(1, $owner);

        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/evidence/'.$session->id)->assertNotFound();
    }

    private function seedChainedSession(int $count, User $user, bool $writeFiles = true, string $status = 'finalized'): EvidenceSession
    {
        $session = EvidenceSession::create([
            'user_id' => $user->id,
            'status' => $status,
            'risk_level' => 'unassessed',
            'started_at' => now()->subMinutes(5),
            'finalized_at' => $status === 'finalized' ? now() : null,
        ]);

        $previous = self::GENESIS;
        $storage = app(EvidenceStorageService::class);

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $payload = $session->id.':'.$sequence;
            $chunkHash = hash('sha256', $payload);
            $cumulative = hash('sha256', $previous.$chunkHash);
            $path = sprintf('evidence/%s/chunks/%010d.bin', $session->id, $sequence);

            if ($writeFiles) {
                $storage->put($path, $payload);
            }

            EvidenceChunk::create([
                'session_id' => $session->id,
                'sequence_number' => $sequence,
                'storage_path' => $path,
                'byte_size' => strlen($payload),
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
