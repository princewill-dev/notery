<?php

namespace Tests\Feature;

use App\Models\Save;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class NoteFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_text_note_can_be_saved_and_retrieved(): void
    {
        $response = $this->post('/save', [
            'writeup' => 'hello world',
            'max_views' => 5,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('code');

        $code = session('code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        $this->get('/' . $code)
            ->assertStatus(200)
            ->assertSee('hello world');

        $this->assertSame(1, Save::first()->views_count);
    }

    public function test_a_consumed_note_keeps_its_attachments_during_the_grace_period(): void
    {
        $save = new Save;
        $save->writeup = Crypt::encryptString('secret note');
        $save->code = hash('sha256', '4321');
        $save->max_views = 1;
        $save->views_count = 0;
        $save->save();

        $save->images()->create([
            'path' => 'notery/example.jpg',
            'image_mime' => 'image/jpeg',
            'size' => 123,
            'is_encrypted' => false,
        ]);

        // The single permitted view renders the note...
        $this->get('/4321')->assertStatus(200)->assertSee('secret note');

        $save->refresh();
        $this->assertSame(1, $save->views_count);
        $this->assertNotNull($save->delete_after, 'consumed note should be scheduled for deletion');

        // ...and does NOT delete the attachment, so the signed links in that
        // very response still work.
        $this->assertDatabaseHas('save_images', ['save_id' => $save->id]);
        $this->assertDatabaseHas('saves', ['id' => $save->id]);
    }

    public function test_a_note_past_its_grace_period_is_purged(): void
    {
        $save = new Save;
        $save->writeup = Crypt::encryptString('gone');
        $save->code = hash('sha256', '8765');
        $save->max_views = 1;
        $save->views_count = 1;
        $save->delete_after = now()->subMinute();
        $save->save();

        $save->images()->create([
            'path' => 'notery/old.jpg',
            'image_mime' => 'image/jpeg',
            'size' => 1,
            'is_encrypted' => false,
        ]);

        $this->get('/8765')->assertStatus(200)->assertSee('no longer available');

        $this->assertDatabaseMissing('saves', ['id' => $save->id]);
        $this->assertDatabaseMissing('save_images', ['save_id' => $save->id]);
    }
}
