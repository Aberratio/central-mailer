<?php

declare(strict_types=1);

namespace CentralMailer\Attachment;

use CentralMailer\Support\Uuid;

final class AttachmentStorage
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<array{filename: string, contentType: string, content: string, sizeBytes: int, sha256: string}> $attachments
     * @return list<array<string, mixed>>
     */
    public function store(string $emailId, array $attachments): array
    {
        if ($attachments === []) {
            return [];
        }

        $directory = $this->emailDirectory($emailId);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create attachment directory');
        }

        $stored = [];
        try {
            foreach ($attachments as $attachment) {
                $storageName = bin2hex(random_bytes(16));
                $relativePath = $emailId . '/' . $storageName;
                $absolutePath = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (file_put_contents($absolutePath, $attachment['content'], LOCK_EX) === false) {
                    throw new \RuntimeException('Unable to store email attachment');
                }

                $stored[] = [
                    'id' => Uuid::v4(),
                    'filename' => $attachment['filename'],
                    'contentType' => $attachment['contentType'],
                    'sizeBytes' => $attachment['sizeBytes'],
                    'sha256' => $attachment['sha256'],
                    'storagePath' => $relativePath,
                ];
            }
        } catch (\Throwable $exception) {
            $this->delete($emailId);
            throw $exception;
        }

        return $stored;
    }

    public function absolutePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_starts_with($relativePath, '\\')) {
            throw new \RuntimeException('Invalid attachment storage path');
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function assertWritable(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Attachment storage directory is not writable');
        }

        $probePath = $this->root . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(8));
        if (file_put_contents($probePath, 'ok', LOCK_EX) === false) {
            throw new \RuntimeException('Attachment storage directory is not writable');
        }

        unlink($probePath);
    }

    public function delete(string $emailId): void
    {
        $directory = $this->emailDirectory($emailId);
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private function emailDirectory(string $emailId): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/i', $emailId) !== 1) {
            throw new \RuntimeException('Invalid email ID for attachment storage');
        }

        return $this->root . DIRECTORY_SEPARATOR . $emailId;
    }

}
