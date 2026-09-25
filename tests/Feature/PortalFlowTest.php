<?php

namespace Tests\Feature;

use App\Models\PortalSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_portal_supports_create_show_poll_message_and_close(): void
    {
        $create = $this->postJson('/portal/create', ['duration' => 5]);
        $create->assertStatus(200)->assertJson(['status' => 'ok']);

        $code = $create->json('code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        $this->get('/p/' . $code)->assertStatus(200)->assertSee((string) $code);

        $this->getJson('/portal/' . $code . '/poll?last_id=0')
            ->assertStatus(200)
            ->assertJson(['status' => 'active']);

        $message = $this->postJson('/portal/' . $code . '/message', [
            'type' => 'text',
            'content' => 'hi there',
        ]);
        $message->assertStatus(200)->assertJson(['status' => 'ok']);
        $lastId = $message->json('message_id');

        // A poll from zero sees the message...
        $this->getJson('/portal/' . $code . '/poll?last_id=0')
            ->assertJsonFragment(['content' => 'hi there']);

        // ...and a poll past that id does not (monotonic id cursor).
        $this->assertCount(0, $this->getJson('/portal/' . $code . '/poll?last_id=' . $lastId)->json('messages'));

        $this->postJson('/portal/' . $code . '/close')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function test_a_third_peer_is_rejected_when_both_slots_are_live(): void
    {
        $portal = PortalSession::create([
            'code' => hash('sha256', '5555'),
            'duration_minutes' => 5,
            'expires_at' => now()->addMinutes(5),
            'status' => 'active',
            'peer_ids' => [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
            ],
            'peer_seen_at' => [
                '11111111-1111-4111-8111-111111111111' => now()->getTimestamp(),
                '22222222-2222-4222-8222-222222222222' => now()->getTimestamp(),
            ],
        ]);

        $this->get('/p/5555')->assertStatus(200)->assertSee('full');
    }

    public function test_idle_peers_are_evicted_and_their_slot_reused(): void
    {
        $portal = PortalSession::create([
            'code' => hash('sha256', '6666'),
            'duration_minutes' => 5,
            'expires_at' => now()->addMinutes(5),
            'status' => 'active',
            'peer_ids' => ['33333333-3333-4333-8333-333333333333'],
            'peer_seen_at' => [
                '33333333-3333-4333-8333-333333333333' => now()->subMinutes(10)->getTimestamp(),
            ],
        ]);

        $this->get('/p/6666')->assertStatus(200);

        $portal->refresh();
        $this->assertNotContains('33333333-3333-4333-8333-333333333333', $portal->peer_ids);
        $this->assertCount(1, $portal->peer_ids);
    }

    public function test_a_closed_portal_cannot_be_joined(): void
    {
        PortalSession::create([
            'code' => hash('sha256', '7777'),
            'duration_minutes' => 5,
            'expires_at' => now()->addMinutes(5),
            'status' => 'closed',
            'peer_ids' => [],
        ]);

        $this->get('/p/7777')->assertStatus(200)->assertSee('closed');
    }

    public function test_a_peer_id_from_the_request_is_not_accepted(): void
    {
        $create = $this->postJson('/portal/create', ['duration' => 5]);
        $code = $create->json('code');
        $peerId = $create->json('peer_id');

        $this->get('/p/' . $code)->assertStatus(200);

        // A different client (no session) presenting the peer id must be rejected.
        $this->flushSession();

        $this->getJson('/portal/' . $code . '/poll?last_id=0&peer_id=' . $peerId)
            ->assertStatus(403);

        $this->postJson('/portal/' . $code . '/message', [
            'type' => 'text',
            'content' => 'spoofed',
            'peer_id' => $peerId,
        ])->assertStatus(403);
    }

    public function test_the_message_limit_is_enforced(): void
    {
        $create = $this->postJson('/portal/create', ['duration' => 5]);
        $code = $create->json('code');
        $this->get('/p/' . $code)->assertStatus(200);

        $portal = PortalSession::first();
        $peerId = $portal->peer_ids[0] ?? 'peer';

        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = [
                'portal_session_id' => $portal->id,
                'type' => 'text',
                'content' => 'x',
                'peer_id' => $peerId,
                'created_at' => now(),
            ];
        }
        \Illuminate\Support\Facades\DB::table('portal_messages')->insert($rows);

        $this->postJson('/portal/' . $code . '/message', [
            'type' => 'text',
            'content' => 'one too many',
        ])->assertStatus(429);
    }
}
