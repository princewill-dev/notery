<?php

namespace Tests\Feature;

use App\Models\Save;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Signed URLs are generated from one origin and validated against another:
 * the `signed` middleware recomputes the signature from $request->url(), which
 * is built from the request's scheme, host and port. A TLS-terminating proxy
 * that reports https while forwarding to this container over port 80 can make
 * those two origins disagree, and every signed link then 403s.
 */
class SignedUrlProxyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What the TLS-terminating proxy sends: the client spoke https, but the
     * request reaches the container on plain HTTP port 80.
     */
    private const PROXY_SERVER = [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_HOST' => 'notery.cc',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'notery.cc',
        'HTTP_X_FORWARDED_PORT' => '80',
    ];

    public function test_redirect_after_save_uses_the_public_origin(): void
    {
        $response = $this->withServerVariables(self::PROXY_SERVER)
            ->post('/save', [
                'writeup' => 'a note to redirect from',
                'max_views' => 1,
            ]);

        $this->assertSame(
            'https://notery.cc',
            $response->headers->get('Location'),
            'the post-save redirect should not carry the proxy port'
        );
    }

    public function test_attachment_download_link_generated_behind_the_proxy_is_valid(): void
    {
        Storage::fake('local');
        Storage::put('notery/secret.jpg', 'jpeg-bytes');

        $save = new Save;
        $save->writeup = Crypt::encryptString('note with an attachment');
        $save->code = hash('sha256', '1234');
        $save->max_views = 1;
        $save->views_count = 0;
        $save->save();

        $image = $save->images()->create([
            'path' => 'notery/secret.jpg',
            'image_mime' => 'image/jpeg',
            'size' => strlen('jpeg-bytes'),
            'is_encrypted' => false,
        ]);

        $html = html_entity_decode(
            $this->withServerVariables(self::PROXY_SERVER)->get('/1234')
                ->assertStatus(200)
                ->getContent()
        );

        $this->assertMatchesRegularExpression(
            '#href="(https://notery\.cc/attachments/' . $image->id . '\?[^"]*signature=[^"]*)"#',
            $html,
            'the note page should link to the attachment on the public origin, without a port'
        );

        preg_match('#href="([^"]*attachments/' . $image->id . '\?[^"]*)"#', $html, $matches);
        $url = $matches[1];

        // The request that validates the signature reports https on port 80.
        // If the generator disagreed about the origin, this would be a 403.
        $this->withServerVariables(self::PROXY_SERVER)
            ->get($url)
            ->assertStatus(200);
    }
}
