<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadCapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
    }

    private function chunkPayload(string $uploadId, int $totalChunks, string $content = '%PDF-1.4 fake'): array
    {
        return [
            'upload_id' => $uploadId,
            'file_index' => 0,
            'chunk_index' => 0,
            'total_chunks' => $totalChunks,
            'original_name' => 'document.pdf',
            'attachment_type' => 'pdf',
            'chunk' => UploadedFile::fake()->createWithContent('document.pdf', $content),
        ];
    }

    public function test_excessive_chunk_count_is_rejected(): void
    {
        $this->post('/upload/chunk', $this->chunkPayload((string) Str::uuid(), 9999))
            ->assertStatus(413);
    }

    public function test_conflicting_total_chunks_for_the_same_file_is_rejected(): void
    {
        $uploadId = (string) Str::uuid();

        $this->post('/upload/chunk', $this->chunkPayload($uploadId, 2))->assertStatus(200);

        $this->post('/upload/chunk', [
            'upload_id' => $uploadId,
            'file_index' => 0,
            'chunk_index' => 1,
            'total_chunks' => 5, // disagrees with the first request
            'original_name' => 'document.pdf',
            'attachment_type' => 'pdf',
            'chunk' => UploadedFile::fake()->createWithContent('document.pdf', 'tail'),
        ])->assertStatus(409);
    }

    public function test_missing_chunks_are_reported_at_assemble_time(): void
    {
        $uploadId = (string) Str::uuid();

        $this->post('/upload/chunk', $this->chunkPayload($uploadId, 3))->assertStatus(200);

        $this->postJson('/upload/assemble', [
            'upload_id' => $uploadId,
            'writeup' => 'note text',
            'attachment_type' => 'pdf',
            'files' => [['index' => 0, 'original_name' => 'document.pdf']],
        ])->assertStatus(400);
    }
}
