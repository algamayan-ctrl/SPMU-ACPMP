<?php

namespace App\Services;

use App\Models\SignatureSnapshot;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SignatureService
{
    public function __construct(private ProtectedFileService $files) {}

    public function snapshot(User $user, string $purpose, ?string $role = null): SignatureSnapshot
    {
        $signature = $user->currentSignature()->with('file')->first();

        if (! $signature) {
            throw ValidationException::withMessages(['signature' => 'Upload an e-signature in your profile before completing this signing action.']);
        }

        $source = $signature->file;
        $snapshot = $this->files->storeBytes(
            $this->files->bytes($source),
            'signature-snapshots',
            'signature-'.$user->id.'-'.$purpose.'.'.pathinfo($source->original_name, PATHINFO_EXTENSION),
            $source->mime_type,
            pathinfo($source->storage_path, PATHINFO_EXTENSION),
            'SIGNATURE_SNAPSHOT',
            $user->id,
        );

        return SignatureSnapshot::query()->create([
            'user_signature_id' => $signature->id,
            'signer_user_id' => $user->id,
            'snapshot_file_id' => $snapshot->id,
            'signer_name' => $user->full_name,
            'signer_role' => $role,
            'purpose_code' => $purpose,
            'sha256' => $snapshot->sha256,
            'captured_at' => now(),
        ]);
    }
}
