<?php

namespace App\Http\Controllers;

use App\Models\TechnicalOperation;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TechnicalOperationController extends Controller
{
    public function backup(Request $request, AuditService $audit): BinaryFileResponse
    {
        abort_unless(config('database.default') === 'sqlite', 409, 'Use the documented database-native backup procedure in MySQL/MariaDB production.');
        $source = DB::connection()->getDatabaseName();
        abort_unless(is_file($source), 404);
        $operation = TechnicalOperation::query()->create([
            'performed_by_user_id' => $request->user()->id,
            'operation_type' => 'LOCAL_SQLITE_BACKUP',
            'status' => 'COMPLETED',
            'reference' => basename($source),
            'details' => 'Local development database backup downloaded. This does not replace the ICTU production backup policy.',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $audit->record('TECHNICAL_BACKUP_DOWNLOADED', $operation, after: ['database' => basename($source)]);

        return response()->download($source, 'spmu-acpmp-local-backup-'.now()->format('Ymd-His').'.sqlite', ['Content-Type' => 'application/octet-stream']);
    }
}
