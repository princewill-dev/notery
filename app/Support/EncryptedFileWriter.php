<?php

namespace App\Support;

use RuntimeException;

/**
 * Buffers plaintext and writes authenticated AES-256-GCM frames. See
 * {@see EncryptedFile} for the on-disk format.
 */
final class EncryptedFileWriter
{
    /** @var resource|null */
    private $handle = null;

    private string $key;

    private string $baseNonce;

    private int $counter = 0;

    private string $buffer = '';

    private bool $closed = false;

    public function __construct(private readonly string $destPath)
    {
        $this->key = EncryptedFile::key();
        $this->baseNonce = random_bytes(8);

        $handle = fopen($destPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open output file: {$destPath}");
        }

        $this->handle = $handle;
        fwrite($this->handle, EncryptedFile::MAGIC);
    }

    public function write(string $data): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot write to a closed encrypted file');
        }

        $this->buffer .= $data;

        while (strlen($this->buffer) >= EncryptedFile::FRAME_PLAINTEXT_BYTES) {
            $this->writeFrame(substr($this->buffer, 0, EncryptedFile::FRAME_PLAINTEXT_BYTES));
            $this->buffer = substr($this->buffer, EncryptedFile::FRAME_PLAINTEXT_BYTES);
        }
    }

    public function close(): void
    {
        if ($this->closed || $this->handle === null) {
            return;
        }

        if ($this->buffer !== '') {
            $this->writeFrame($this->buffer);
            $this->buffer = '';
        }

        fclose($this->handle);
        $this->handle = null;
        $this->closed = true;
    }

    /**
     * Remove the partially written file (used on failure).
     */
    public function abort(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        $this->closed = true;
        @unlink($this->destPath);
    }

    private function writeFrame(string $plaintext): void
    {
        $nonce = $this->baseNonce . pack('N', $this->counter++);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            EncryptedFile::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            EncryptedFile::TAG_LENGTH
        );

        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt a file frame');
        }

        fwrite($this->handle, pack('N', strlen($cipher)) . $nonce . $cipher . $tag);
    }
}
