<?php

namespace App\Services;

use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\RequestVersion;
use App\Models\SignatureSnapshot;
use App\Models\User;

class DocumentService
{
    public function __construct(private SimplePdfService $pdf, private ProtectedFileService $files) {}

    public function requestLetter(BorrowingRequest $request, bool $final = false): GeneratedDocument
    {
        $request->loadMissing(['borrower.organizationalUnit', 'accountableUnit', 'currentVersion.items.inventoryItem.unit', 'currentVersion.borrowerSignature.file', 'currentVersion.approvalSteps.approver', 'currentVersion.approvalSteps.signatureSnapshot.file']);
        $version = $request->currentVersion;
        $type = $final ? 'APPROVED_REQUEST_LETTER' : 'REQUEST_LETTER';
        $status = $final ? 'FINAL' : 'DRAFT';
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES',
            'SUPPLY AND PROPERTY MANAGEMENT UNIT',
            '',
            $final ? 'FULLY APPROVED BORROWING REQUEST LETTER' : 'BORROWING REQUEST LETTER',
            'Request No.: '.$request->request_no,
            'Document status: '.$status,
            'Version: '.$version->version_no,
            'Generated: '.now()->format('F j, Y g:i A').' Asia/Manila',
            '',
            'Borrower: '.$request->borrower->full_name,
            'Employee No.: '.$request->borrower->employee_no,
            'Office/Department: '.$request->accountableUnit->unit_name,
            'Purpose/Event: '.$version->purpose_event,
            'Event details: '.($version->event_details ?: 'Not applicable'),
            'Location: '.$version->location,
            'Needed from: '.$version->needed_from->format('F j, Y g:i A'),
            'Return deadline: '.$version->return_due_at->format('F j, Y g:i A'),
            'Represented organization: '.($version->student_organization ?: 'Not applicable'),
            'Program/Department and year: '.trim(($version->represented_program_department ?: 'Not applicable').' '.($version->represented_year_level ?: '')),
            'Off-campus barricades: '.($version->items->contains('use_location', 'OFF_CAMPUS') ? 'Yes' : 'No'),
            '',
            'REQUESTED ITEMS',
        ];

        foreach ($version->items as $item) {
            $lines[] = sprintf('%s | %s %s | %s', $item->description_snapshot, $item->requested_quantity + 0, $item->unit_snapshot, str_replace('_', '-', $item->use_location));
        }

        $lines[] = '';
        $lines[] = 'BORROWER CERTIFICATION AND E-SIGNATURE';
        $lines[] = 'I certify that the information above is accurate and accept accountability for the requested properties.';
        $lines[] = '/s/ '.$request->borrower->full_name.' | Signed: '.optional($version->signed_at)->format('F j, Y g:i A');
        $lines[] = 'Signature snapshot: '.optional($version->borrowerSignature)->sha256;

        if ($final) {
            $lines[] = '';
            $lines[] = 'DIGITAL APPROVALS';
            foreach ($version->approvalSteps->sortBy('sequence_no') as $step) {
                $lines[] = sprintf(
                    '%s | /s/ %s | Received: %s | Approved: %s | Signature: %s',
                    $step->stage_code->value,
                    $step->approver?->full_name,
                    optional($step->received_at)->format('M j, Y g:i A'),
                    optional($step->decided_at)->format('M j, Y g:i A'),
                    $step->signatureSnapshot?->sha256,
                );
            }
            $lines[] = '';
            $lines[] = 'This fully approved letter must be downloaded by '.$request->download_deadline_at?->format('F j, Y g:i A').' Asia/Manila to unlock the Borrower Slip.';
        }

        $signatures = [[
            'label' => 'Accountable Borrower', 'snapshot' => $version->borrowerSignature,
            'name' => $request->borrower->full_name, 'time' => $version->signed_at,
        ]];
        if ($final) {
            foreach ($version->approvalSteps->sortBy('sequence_no') as $step) {
                $signatures[] = [
                    'label' => $step->stage_code->value.' Approval'.($step->temporary_delegation_id ? ' - Temporary Delegate' : ''),
                    'snapshot' => $step->signatureSnapshot, 'name' => $step->approver?->full_name, 'time' => $step->decided_at,
                ];
            }
        }

        return $this->saveHtml($type, $this->officialHtml($final ? 'Fully Approved Borrowing Request Letter' : 'Borrowing Request Letter', $lines, $signatures), $version, $request::class, $request->id, $status, $request->request_no.'-'.$type.'.pdf');
    }

    public function borrowerSlip(CustodyTransaction $custody): GeneratedDocument
    {
        $custody->loadMissing(['request.borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem', 'acknowledgementSignature.file']);
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU',
            "BORROWER'S SLIP",
            'Custody No.: '.$custody->custody_no,
            'Request No.: '.$custody->request->request_no,
            'Borrower: '.$custody->request->borrower->full_name,
            'Purpose: '.$custody->request->currentVersion->purpose_event,
            'Release schedule: '.optional($custody->scheduled_release_at)->format('F j, Y g:i A'),
            'Return deadline: '.$custody->due_at->format('F j, Y g:i A'),
            '',
            'APPROVED ITEMS - protected fields',
        ];
        foreach ($custody->lines as $line) {
            $lines[] = sprintf(
                '%s | Approved: %s %s | Final issued: %s | %s | Status: %s',
                $line->requestItem->description_snapshot,
                $line->approved_quantity + 0,
                $line->requestItem->unit_snapshot,
                $line->quantity_to_receive + 0,
                str_replace('_', '-', $line->requestItem->use_location),
                str_replace('_', ' ', $line->item_status),
            );
        }
        $lines[] = '';
        $lines[] = 'Borrower acknowledgement: I accept accountability for the actual quantities released to me.';
        $lines[] = '/s/ '.$custody->request->borrower->full_name.' | Acknowledged: '.optional($custody->acknowledged_at)->format('F j, Y g:i A');
        $lines[] = 'Attached e-signature snapshot: '.($custody->acknowledgementSignature?->sha256 ?: 'Pending acknowledgement');
        $lines[] = 'SPMU releasing officer: '.($custody->released_by_user_id ? 'Recorded in the custody transaction' : 'Pending physical release');

        return $this->saveHtml('BORROWER_SLIP', $this->officialHtml("Borrower's Slip", $lines, [[
            'label' => 'Borrower Acknowledgement', 'snapshot' => $custody->acknowledgementSignature,
            'name' => $custody->borrower->full_name, 'time' => $custody->acknowledged_at,
        ]]), $custody->request->currentVersion, $custody::class, $custody->id, 'FINAL', $custody->custody_no.'-BORROWER-SLIP.pdf');
    }

    public function conditionalForm(CustodyTransaction $custody, string $type): GeneratedDocument
    {
        $custody->loadMissing([
            'request.borrower', 'request.currentVersion', 'lines.requestItem.inventoryItem',
            'gatePass.preparedVerifier', 'gatePass.approver', 'gatePass.delegation', 'gatePass.preparedVerifierSignature.file', 'gatePass.approverSignature.file',
            'borrower', 'laundryBorrowerSignature.file', 'laundryApproverSignature.file',
        ]);
        $title = $type === 'GATE_PASS' ? 'GATE PASS' : 'LAUNDRY SERVICE FORM';
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU',
            $title,
            'Custody No.: '.$custody->custody_no,
            'Borrower/Bearer: '.$custody->request->borrower->full_name,
            'Purpose: '.$custody->request->currentVersion->purpose_event,
            'Location: '.$custody->request->currentVersion->location,
            '',
            'ITEMS',
        ];
        $documentLines = $custody->lines->filter(fn ($line) => $type === 'GATE_PASS'
            ? $line->requestItem->use_location === 'OFF_CAMPUS'
            : (bool) $line->requestItem->inventoryItem?->laundry_required);
        foreach ($documentLines as $line) {
            $lines[] = sprintf('%s | %s %s', $line->requestItem->description_snapshot, $line->quantity_to_receive + 0, $line->requestItem->unit_snapshot);
        }
        $lines[] = '';
        if ($type === 'GATE_PASS') {
            $gatePass = $custody->gatePass;
            $lines[] = 'VERIFIED BY (digital): '.($gatePass?->preparedVerifier ? '/s/ '.$gatePass->preparedVerifier->full_name : 'Pending');
            $lines[] = 'Date/time: '.optional($gatePass?->prepared_verified_at)->format('F j, Y g:i A');
            $lines[] = 'APPROVED BY (digital): '.($gatePass?->approver ? '/s/ '.$gatePass->approver->full_name : 'Pending');
            $lines[] = 'Date/time: '.optional($gatePass?->approved_at)->format('F j, Y g:i A').($gatePass?->delegation ? ' | Temporary delegated approver' : '');
            $lines[] = '';
            $lines[] = 'GUARD ON DUTY (wet signature after exit)';
            $lines[] = 'Released by: ____________________ Date: __________ Time: __________ Signature: __________';
        } else {
            $lines[] = 'BORROWER ACKNOWLEDGEMENT (digital): '.($custody->laundry_borrower_signature_snapshot_id ? '/s/ '.$custody->borrower->full_name : 'Pending');
            $lines[] = 'Date/time: '.optional($custody->acknowledged_at)->format('F j, Y g:i A');
            $approver = $custody->laundry_approved_by_user_id ? User::find($custody->laundry_approved_by_user_id) : null;
            $lines[] = 'APPROVED BY - SPMU HEAD (digital): '.($approver ? '/s/ '.$approver->full_name : 'Pending');
            $lines[] = 'Date/time: '.optional($custody->laundry_approved_at)->format('F j, Y g:i A').($custody->laundry_temporary_delegation_id ? ' | Temporary delegated approver' : '');
            $lines[] = '';
            $lines[] = 'LAUNDRY WORKER (wet signature)';
            $lines[] = 'Received by: __________________ Date: ______ Completed: ______ Signature: ______';
        }
        $lines[] = 'Return the signed original to SPMU. The borrower uploads the scan; a recorded SPMU fallback remains auditable.';

        $signatures = $type === 'GATE_PASS' ? [
            ['label' => 'Verified By - SPMU Action Officer', 'snapshot' => $custody->gatePass?->preparedVerifierSignature, 'name' => $custody->gatePass?->preparedVerifier?->full_name, 'time' => $custody->gatePass?->prepared_verified_at],
            ['label' => 'Approved By - SPMU Head', 'snapshot' => $custody->gatePass?->approverSignature, 'name' => $custody->gatePass?->approver?->full_name, 'time' => $custody->gatePass?->approved_at],
        ] : [
            ['label' => 'Borrower Acknowledgement', 'snapshot' => $custody->laundryBorrowerSignature, 'name' => $custody->borrower->full_name, 'time' => $custody->acknowledged_at],
            ['label' => 'Approved By - SPMU Head', 'snapshot' => $custody->laundryApproverSignature, 'name' => User::find($custody->laundry_approved_by_user_id)?->full_name, 'time' => $custody->laundry_approved_at],
        ];

        return $this->saveHtml($type, $this->officialHtml($title, $lines, $signatures), $custody->request->currentVersion, $custody::class, $custody->id, 'FINAL', $custody->custody_no.'-'.$type.'.pdf');
    }

    public function replaceConditionalForm(CustodyTransaction $custody, string $type): GeneratedDocument
    {
        $this->supersede($custody, $type, 'Replaced after a required digital signature or custody-field update.');
        $document = $this->conditionalForm($custody->fresh(), $type);
        if ($type === 'GATE_PASS' && $custody->gatePass) {
            $custody->gatePass->update(['pass_document_id' => $document->id]);
        }
        $this->refreshPacketIfReady($custody->fresh());

        return $document;
    }

    public function refreshPacketIfReady(CustodyTransaction $custody): ?GeneratedDocument
    {
        $custody->loadMissing([
            'request.borrower', 'request.currentVersion.borrowerSignature.file', 'request.currentVersion.approvalSteps.approver', 'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem', 'acknowledgementSignature.file', 'gatePass.preparedVerifier', 'gatePass.approver',
            'gatePass.preparedVerifierSignature.file', 'gatePass.approverSignature.file', 'laundryBorrowerSignature.file', 'laundryApproverSignature.file',
        ]);
        if (! $custody->acknowledged_at) {
            return null;
        }
        $hasGatePass = $custody->lines->contains(fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS');
        $hasLaundry = $custody->lines->contains(fn ($line) => (bool) $line->requestItem->inventoryItem?->laundry_required);
        if (($hasGatePass && (! $custody->gatePass?->prepared_verified_at || ! $custody->gatePass?->approved_at)) || ($hasLaundry && ! $custody->laundry_approved_at)) {
            return null;
        }

        $this->supersede($custody, 'OFFICIAL_FORM_PACKET', 'Replaced by the latest approved packet.');
        $pages = [
            [
                'CAMARINES SUR POLYTECHNIC COLLEGES', 'SUPPLY AND PROPERTY MANAGEMENT UNIT', '',
                'FULLY APPROVED BORROWING REQUEST LETTER', 'Request No.: '.$custody->request->request_no,
                'Borrower: '.$custody->borrower->full_name, 'Purpose/Event: '.$custody->request->currentVersion->purpose_event,
                'Needed from: '.$custody->request->currentVersion->needed_from->format('F j, Y g:i A'),
                'Return deadline: '.$custody->due_at->format('F j, Y g:i A'), '', 'The signed approval record is preserved in the approved request letter document.',
            ],
            [
                'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU', "BORROWER'S SLIP", 'Custody No.: '.$custody->custody_no,
                'Borrower: '.$custody->borrower->full_name, 'Return deadline: '.$custody->due_at->format('F j, Y g:i A'), '',
                ...$custody->lines->map(fn ($line) => sprintf('%s | %s %s | %s', $line->requestItem->description_snapshot, $line->quantity_to_receive + 0, $line->requestItem->unit_snapshot, str_replace('_', '-', $line->requestItem->use_location)))->all(),
                '', 'BORROWER ACKNOWLEDGEMENT: /s/ '.$custody->borrower->full_name, 'Signed: '.$custody->acknowledged_at->format('F j, Y g:i A'),
            ],
        ];
        if ($hasGatePass) {
            $pages[] = [
                'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU', 'GATE PASS', 'Custody No.: '.$custody->custody_no, '',
                ...$custody->lines->filter(fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS')->map(fn ($line) => sprintf('%s | %s %s', $line->requestItem->description_snapshot, $line->quantity_to_receive + 0, $line->requestItem->unit_snapshot))->all(),
                '', 'VERIFIED BY: /s/ '.$custody->gatePass->preparedVerifier->full_name.' | '.$custody->gatePass->prepared_verified_at->format('F j, Y g:i A'),
                'APPROVED BY: /s/ '.$custody->gatePass->approver->full_name.' | '.$custody->gatePass->approved_at->format('F j, Y g:i A'),
                '', 'GUARD ON DUTY (wet signature): ____________________ Date/time: ____________________',
            ];
        }
        if ($hasLaundry) {
            $approver = User::find($custody->laundry_approved_by_user_id);
            $pages[] = [
                'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU', 'LAUNDRY SERVICE FORM', 'Custody No.: '.$custody->custody_no, '',
                ...$custody->lines->filter(fn ($line) => (bool) $line->requestItem->inventoryItem?->laundry_required)->map(fn ($line) => sprintf('%s | %s %s', $line->requestItem->description_snapshot, $line->quantity_to_receive + 0, $line->requestItem->unit_snapshot))->all(),
                '', 'BORROWER: /s/ '.$custody->borrower->full_name.' | '.$custody->acknowledged_at->format('F j, Y g:i A'),
                'APPROVED BY - SPMU HEAD: /s/ '.$approver?->full_name.' | '.$custody->laundry_approved_at->format('F j, Y g:i A'),
                '', 'LAUNDRY WORKER (wet signature): ____________________ Received: __________ Completed: __________',
            ];
        }

        $requestSignatures = [[
            'label' => 'Accountable Borrower', 'snapshot' => $custody->request->currentVersion->borrowerSignature,
            'name' => $custody->borrower->full_name, 'time' => $custody->request->currentVersion->signed_at,
        ]];
        foreach ($custody->request->currentVersion->approvalSteps->sortBy('sequence_no') as $step) {
            $requestSignatures[] = [
                'label' => $step->stage_code->value.' Approval'.($step->temporary_delegation_id ? ' - Temporary Delegate' : ''),
                'snapshot' => $step->signatureSnapshot, 'name' => $step->approver?->full_name, 'time' => $step->decided_at,
            ];
        }
        $pageSignatures = [$requestSignatures, [[
            'label' => 'Borrower Acknowledgement', 'snapshot' => $custody->acknowledgementSignature,
            'name' => $custody->borrower->full_name, 'time' => $custody->acknowledged_at,
        ]]];
        if ($hasGatePass) {
            $pageSignatures[] = [
                ['label' => 'Verified By - SPMU Action Officer', 'snapshot' => $custody->gatePass->preparedVerifierSignature, 'name' => $custody->gatePass->preparedVerifier->full_name, 'time' => $custody->gatePass->prepared_verified_at],
                ['label' => 'Approved By - SPMU Head', 'snapshot' => $custody->gatePass->approverSignature, 'name' => $custody->gatePass->approver->full_name, 'time' => $custody->gatePass->approved_at],
            ];
        }
        if ($hasLaundry) {
            $pageSignatures[] = [
                ['label' => 'Borrower Acknowledgement', 'snapshot' => $custody->laundryBorrowerSignature, 'name' => $custody->borrower->full_name, 'time' => $custody->acknowledged_at],
                ['label' => 'Approved By - SPMU Head', 'snapshot' => $custody->laundryApproverSignature, 'name' => $approver?->full_name, 'time' => $custody->laundry_approved_at],
            ];
        }
        $htmlPages = [];
        foreach ($pages as $index => $page) {
            $htmlPages[] = $this->officialHtml((string) ($page[3] ?? $page[1] ?? 'Official Form'), $page, $pageSignatures[$index] ?? [], false);
        }

        return $this->saveHtml('OFFICIAL_FORM_PACKET', '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.implode('<div class="page-break"></div>', $htmlPages).'</body></html>', $custody->request->currentVersion, $custody::class, $custody->id, 'FINAL', $custody->custody_no.'-OFFICIAL-PACKET.pdf');
    }

    public function billingStatement(BillingStatement $billing): GeneratedDocument
    {
        $billing->loadMissing(['borrower', 'lines']);
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU',
            'BILLING STATEMENT - PENALTIES AND PROPERTY CHARGES ONLY',
            'Billing No.: '.$billing->billing_no,
            'Borrower: '.$billing->borrower->full_name,
            'Issued: '.$billing->issued_at->format('F j, Y'),
            '',
        ];
        foreach ($billing->lines as $line) {
            $lines[] = sprintf('%s | %s | PHP %s', $line->line_type, $line->description, number_format((float) $line->amount, 2));
        }
        $lines[] = '';
        $lines[] = 'TOTAL: PHP '.number_format((float) $billing->total_amount, 2);
        $lines[] = 'Payment is processed externally through Accounting/Cashier. Submit Official Receipt evidence to SPMU for verification.';

        return $this->save('BILLING_STATEMENT', $lines, null, $billing::class, $billing->id, 'FINAL', $billing->billing_no.'.pdf');
    }

    public function rslddp(Incident $incident): GeneratedDocument
    {
        $incident->loadMissing(['borrower', 'custody.request.currentVersion', 'lines']);
        $lines = [
            'CAMARINES SUR POLYTECHNIC COLLEGES - SPMU',
            'OFFICIAL RSLDDP REPORT',
            'Controlled output enabled after client approval of the configured template status.',
            'Incident No.: '.$incident->incident_no,
            'Borrower: '.$incident->borrower->full_name,
            'Custody No.: '.$incident->custody->custody_no,
            'Incident type: '.$incident->incident_type,
            'Reported: '.$incident->reported_at->format('F j, Y g:i A'),
            'Police blotter reference: '.($incident->police_blotter_reference ?: 'Not applicable'),
            'Remarks: '.($incident->remarks ?: 'None'),
            '',
            'AFFECTED PROPERTY',
        ];
        foreach ($incident->lines as $line) {
            $lines[] = sprintf('Custody line %s | Quantity: %s | Condition: %s', $line->custody_line_id, $line->quantity + 0, $line->observed_condition);
        }
        $document = $this->save(
            'RSLDDP',
            $lines,
            $incident->custody->request->currentVersion,
            $incident::class,
            $incident->id,
            'FINAL',
            $incident->incident_no.'-RSLDDP.pdf',
        );
        $incident->update(['rslddp_reference' => $document->document_no]);

        return $document;
    }

    /** @param list<string> $lines */
    private function save(string $type, array $lines, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename): GeneratedDocument
    {
        $bytes = $this->pdf->make($lines);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'stored_file_id' => $file->id,
            'request_version_id' => $version?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type,
            'version_no' => $version?->version_no ?? 1,
            'sha256' => $file->sha256,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    /** @param list<list<string>> $pages */
    private function savePages(string $type, array $pages, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename): GeneratedDocument
    {
        $bytes = $this->pdf->makePages($pages);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'stored_file_id' => $file->id,
            'request_version_id' => $version?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type,
            'version_no' => $version?->version_no ?? 1,
            'sha256' => $file->sha256,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    private function saveHtml(string $type, string $html, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename): GeneratedDocument
    {
        $bytes = $this->pdf->html($html);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'stored_file_id' => $file->id, 'request_version_id' => $version?->id,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type, 'version_no' => $version?->version_no ?? 1, 'sha256' => $file->sha256,
            'status' => $status, 'generated_at' => now(),
        ]);
    }

    /** @param list<array{label:string,snapshot:?SignatureSnapshot,name:?string,time:mixed}> $signatures */
    private function officialHtml(string $title, array $lines, array $signatures = [], bool $documentShell = true): string
    {
        $body = '<section class="official"><header><div class="seal">CSPC</div><div><strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong><span>Supply and Property Management Unit</span></div></header><h1>'.e($title).'</h1><div class="lines">';
        foreach ($lines as $line) {
            $body .= $line === '' ? '<div class="spacer"></div>' : '<p>'.e($line).'</p>';
        }
        $body .= '</div>';
        $available = array_filter($signatures, fn ($signature) => $signature['snapshot'] instanceof SignatureSnapshot);
        if ($available) {
            $body .= '<div class="signature-grid">';
            foreach ($signatures as $signature) {
                if (! $signature['snapshot'] instanceof SignatureSnapshot) {
                    continue;
                }
                $body .= '<div class="signature-block"><small>'.e($signature['label']).'</small>'.$this->signatureImage($signature['snapshot']).'<strong>'.e((string) $signature['name']).'</strong><span>'.e(optional($signature['time'])->format('F j, Y g:i A') ?? '').'</span><code>'.e(substr($signature['snapshot']->sha256, 0, 20)).'…</code></div>';
            }
            $body .= '</div>';
        }
        $body .= '<footer>Controlled digital document · Asia/Manila · Integrity is recorded in SPMU-ACPMP</footer></section>';

        return $documentShell ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>' : $body;
    }

    private function signatureImage(SignatureSnapshot $snapshot): string
    {
        $snapshot->loadMissing('file');
        if (! $snapshot->file) {
            return '<div class="signature-missing">Signature file unavailable</div>';
        }
        $bytes = $this->files->bytes($snapshot->file);
        $data = 'data:'.$snapshot->file->mime_type.';base64,'.base64_encode($bytes);

        return '<img class="signature-image" src="'.e($data).'" alt="E-signature of '.e($snapshot->signer_name).'">';
    }

    private function officialCss(): string
    {
        return '<style>@page{margin:34px 42px}*{box-sizing:border-box}body{margin:0;color:#16314c;font-family:DejaVu Sans,Arial,sans-serif;font-size:10px}.official{min-height:720px;position:relative;padding-bottom:36px}.official header{display:flex;align-items:center;gap:12px;padding-bottom:12px;border-bottom:2px solid #0b3156}.seal{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:10px;background:#0b3156;color:#fff;font-weight:bold}.official header strong,.official header span{display:block}.official header strong{font-size:13px}.official header span{color:#60758a}.official h1{text-align:center;margin:22px 0 18px;color:#0b3156;font-size:17px;text-transform:uppercase}.lines p{margin:0 0 6px;padding:0 0 4px;border-bottom:1px solid #e1e8ef}.spacer{height:8px}.signature-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:18px}.signature-block{min-height:125px;padding:10px;border:1px solid #cdd9e5;border-radius:8px;text-align:center}.signature-block small,.signature-block strong,.signature-block span,.signature-block code{display:block}.signature-block small{color:#60758a;text-transform:uppercase}.signature-block span,.signature-block code{font-size:8px;color:#60758a}.signature-image{display:block;max-width:170px;max-height:60px;margin:5px auto}.signature-missing{height:55px;padding-top:20px;color:#8b97a4}.official footer{position:absolute;bottom:0;left:0;right:0;padding-top:8px;border-top:1px solid #dbe3eb;color:#6c7d8d;text-align:center;font-size:8px}.page-break{page-break-after:always}</style>';
    }

    private function supersede(CustodyTransaction $custody, string $type, string $reason): void
    {
        GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', $type)
            ->where('status', 'FINAL')
            ->update(['status' => 'SUPERSEDED', 'invalidated_at' => now(), 'invalidation_reason' => $reason]);
    }
}
