<?php

namespace App\Services;

use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProtectedFileService
{
    public function storeUpload(UploadedFile $upload, string $folder, string $classification = 'PROTECTED'): StoredFile
    {
        $bytes = file_get_contents($upload->getRealPath());
        $extension = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension() ?: 'bin');

        return $this->storeBytes($bytes, $folder, $upload->getClientOriginalName(), $upload->getMimeType() ?: 'application/octet-stream', $extension, $classification);
    }

    public function storeBytes(string $bytes, string $folder, string $originalName, string $mimeType, string $extension, string $classification = 'PROTECTED', ?int $uploaderId = null): StoredFile
    {
        $path = trim($folder, '/').'/'.Str::uuid().'.'.ltrim($extension, '.');
        Storage::disk('local')->put($path, $bytes);

        return StoredFile::query()->create([
            'uploaded_by_user_id' => $uploaderId ?? auth()->id(),
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => $classification,
        ]);
    }

    public function bytes(StoredFile $file): string
    {
        return Storage::disk($file->disk)->get($file->storage_path);
    }
}
