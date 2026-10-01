<?php

namespace App\Support;

use RuntimeException;

/**
 * Streaming authenticated encryption for files at rest.
 *
 * Files are written in independently authenticated AES-256-GCM frames so they
 * can be processed without loading the whole file into memory:
 *
 *   magic "NTRYENC1" (8 bytes)
 *   repeated: [uint32 ciphertext length][12-byte nonce][ciphertext][16-byte tag]
 *
 * The key is derived from APP_KEY. Each frame uses a unique nonce built from a
 * random 8-byte prefix plus a 32-bit counter.
 */
final class EncryptedFile
{
    public const MAGIC = "NTRYENC1";
    public const CIPHER = 'aes-256-gcm';
    public const NONCE_LENGTH = 12;
    public const TAG_LENGTH = 16;
    public const FRAME_PLAINTEXT_BYTES = 1024 * 1024;

    /**
     * Derive the 32-byte encryption key from the application key.
     */
    public static function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                $appKey = $decoded;
            }
        }

        return hash('sha256', 'notery-file-encryption|' . $appKey, true);
    }

    /**
     * Whether the given path holds a stream in this format.
     */
    public static function isEncrypted(string $path): bool
    {
        if (!is_file($path) || filesize($path) < strlen(self::MAGIC)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * Encrypt a plaintext file to $destPath, replacing any existing file.
     */
    public static function encryptFile(string $sourcePath, string $destPath): void
    {
        $writer = new EncryptedFileWriter($destPath);
        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            $writer->abort();
            throw new RuntimeException("Unable to read source file: {$sourcePath}");
        }

        try {
            while (!feof($in)) {
                $data = fread($in, self::FRAME_PLAINTEXT_BYTES);
                if ($data === false) {
                    throw new RuntimeException("Failed reading source file: {$sourcePath}");
                }
                if ($data !== '') {
                    $writer->write($data);
                }
            }
        } catch (\Throwable $e) {
            fclose($in);
            $writer->abort();
            throw $e;
        }

        fclose($in);
        $writer->close();
    }

    /**
     * Decrypt a stream to a temporary file and return its path. The caller is
     * responsible for deleting it (response()->file(...)->deleteFileAfterSend()).
     */
    public static function decryptToTemp(string $sourcePath): string
    {
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException("Unable to read encrypted file: {$sourcePath}");
        }

        $magic = fread($in, strlen(self::MAGIC));
        if ($magic !== self::MAGIC) {
            fclose($in);
            throw new RuntimeException('File is not in the expected encrypted format');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'notery_dec_');
        if ($tmp === false) {
            fclose($in);
            throw new RuntimeException('Unable to create a temporary file');
        }

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            @unlink($tmp);
            throw new RuntimeException('Unable to open a temporary file for writing');
        }

        $key = self::key();

        try {
            while (!feof($in)) {
                $lengthBytes = fread($in, 4);
                if ($lengthBytes === false || strlen($lengthBytes) < 4) {
                    break; // clean end of stream
                }

                $length = unpack('N', $lengthBytes)[1];
                if ($length < 0 || $length > 64 * 1024 * 1024) {
                    throw new RuntimeException('Encrypted frame length is invalid');
                }

                $nonce = fread($in, self::NONCE_LENGTH);
                $cipher = $length > 0 ? fread($in, $length) : '';
                $tag = fread($in, self::TAG_LENGTH);

                if (strlen($nonce) !== self::NONCE_LENGTH
                    || strlen($cipher) !== $length
                    || strlen($tag) !== self::TAG_LENGTH) {
                    throw new RuntimeException('Encrypted frame is truncated');
                }

                $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
                if ($plain === false) {
                    throw new RuntimeException('Encrypted frame failed authentication');
                }

                fwrite($out, $plain);
            }
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($in);
        fclose($out);

        return $tmp;
    }
}
