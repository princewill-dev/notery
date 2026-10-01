<?php

namespace Tests\Feature;

use App\Models\PortalMessage;
use App\Models\SaveImage;
use App\Support\EncryptedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_encrypted_file_round_trip_across_multiple_frames(): void
    {
        $plain = str_repeat('A', (2 * 1024 * 1024) + 123);
        $src = tempnam(sys_get_temp_dir(), 'plain_');
        $dest = tempnam(sys_get_temp_dir(), 'enc_');
        file_put_contents($src, $plain);

        EncryptedFile::encryptFile($src, $dest);

        $this->assertTrue(EncryptedFile::isEncrypted($dest));
        $this->assertStringNotContainsString('AAAA', (string) file_get_contents($dest));

        $decrypted = EncryptedFile::decryptToTemp($dest);
        $this->assertSame(hash_file('sha256', $src), hash_file('sha256', $decrypted));

        @unlink($src);
        @unlink($dest);
        @unlink($decrypted);
    }

    public function test_tampered_encrypted_file_fails_authentication(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'plain_');
        $dest = tempnam(sys_get_temp_dir(), 'enc_');
        file_put_contents($src, 'sensitive payload that must stay private');

        EncryptedFile::encryptFile($src, $dest);

        // Corrupt one ciphertext byte.
        $bytes = file_get_contents($dest);
        $bytes[strlen($bytes) - 20] = chr(ord($bytes[strlen($bytes) - 20]) ^ 0xFF);
        file_put_contents($dest, $bytes);

        $this->expectException(RuntimeException::class);
        EncryptedFile::decryptToTemp($dest);

        @unlink($src);
        @unlink($dest);
    }

    public function test_portal_text_is_encrypted_at_rest_and_readable_via_poll(): void
    {
        $create = $this->postJson('/portal/create', ['duration' => 5]);
        $code = $create->json('code');
        $this->get('/p/' . $code)->assertStatus(200);

        $this->postJson('/portal/' . $code . '/message', [
            'type' => 'text',
            'content' => 'top secret message',
        ])->assertStatus(200);

        $row = PortalMessage::first();
        $this->assertTrue((bool) $row->is_encrypted);
        $this->assertStringNotContainsString('top secret', (string) $row->content);

        $this->getJson('/portal/' . $code . '/poll?last_id=0')
            ->assertJsonFragment(['content' => 'top secret message']);
    }

    public function test_note_attachment_is_encrypted_at_rest_and_downloadable(): void
    {
        Storage::fake('local');
        $uploadId = (string) Str::uuid();

        $this->post('/upload/chunk', [
            'upload_id' => $uploadId,
            'file_index' => 0,
            'chunk_index' => 0,
            'total_chunks' => 1,
            'original_name' => 'doc.pdf',
            'attachment_type' => 'pdf',
            'chunk' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 hello attachment'),
        ])->assertStatus(200);

        $this->postJson('/upload/assemble', [
            'upload_id' => $uploadId,
            'writeup' => 'a note with a file',
            'attachment_type' => 'pdf',
            'files' => [['index' => 0, 'original_name' => 'doc.pdf']],
        ])->assertStatus(200);

        $image = SaveImage::first();
        $this->assertNotNull($image);
        $this->assertTrue((bool) $image->is_encrypted);
        $this->assertStringNotContainsString('%PDF', (string) Storage::get($image->path));

        $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(5), ['id' => $image->id]);
        $this->get($url)->assertStatus(200);
    }
}
