<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function __construct(private SimplePdfService $pdf, private ProtectedFileService $files) {}

    public function requestLetter(BorrowingRequest $request, bool $final = false): GeneratedDocument
    {
        $request->loadMissing('currentVersion');
        $version = $request->currentVersion;
        $type = $final ? 'APPROVED_REQUEST_LETTER' : 'REQUEST_LETTER';
        $status = $final ? 'FINAL' : 'DRAFT';

        return $this->saveHtml(
            $type,
            $this->requestLetterHtml($request, $final),
            $version,
            $request::class,
            $request->id,
            $status,
            $request->request_no.'-'.$type.'.pdf',
            true,
        );
    }

    public function requestLetterHtml(BorrowingRequest $request, bool $final = false, ?CarbonInterface $generatedAt = null): string
    {
        $request->loadMissing([
            'borrower.organizationalUnit',
            'accountableUnit',
            'currentVersion.items.inventoryItem.unit',
            'currentVersion.approvalSteps.approver',
        ]);
        $version = $request->currentVersion;
        if (! $version) {
            throw ValidationException::withMessages(['document' => 'A current request version is required to render the Borrowing Request Letter.']);
        }

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        if (! is_file($logoPath)) {
            throw ValidationException::withMessages(['document' => 'The institutional logo asset is unavailable.']);
        }

        $approvals = $final
            ? $version->approvalSteps
                ->filter(fn ($step) => $step->stage_code->value === 'SPMU')
                ->sortBy('sequence_no')
                ->map(fn ($step): array => [
                    'stage' => 'SPMU',
                    'name' => $step->approver?->full_name,
                    'decided_at_formal' => $this->formalDateTime($step->decided_at),
                    'decision' => $step->decision,
                ])->values()
            : collect();

        $generatedAt = ($generatedAt ?? now())->setTimezone('Asia/Manila');
        $borrowerDesignation = trim((string) $request->borrower->designation);
        if ($borrowerDesignation === '' || $borrowerDesignation === $request->borrower->access_classification?->label()) {
            $borrowerDesignation = UserRole::Borrower->label();
        }

        return view('documents.borrowing-request-letter', [
            'borrowingRequest' => $request,
            'version' => $version,
            'isFinal' => $final,
            'documentStatus' => $final ? 'Fully Approved' : 'Draft',
            'visibleGeneratedAt' => $this->formalDateTime($generatedAt),
            'visibleNeededFrom' => $this->formalDateTime($version->needed_from),
            'visibleReturnDueAt' => $this->formalDateTime($version->return_due_at),
            'visibleSignedAt' => $this->formalDateTime($version->signed_at),
            'visibleDownloadDeadline' => $this->formalDateTime($request->download_deadline_at),
            'logoDataUri' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)),
            'borrowerDesignation' => $borrowerDesignation,
            'approvals' => $approvals,
        ])->render();
    }

    /** @return array{document: GeneratedDocument, generated: bool} */
    public function recoverMissingDraftRequestLetter(BorrowingRequest $request): array
    {
        return DB::transaction(function () use ($request): array {
            $lockedRequest = BorrowingRequest::query()->lockForUpdate()->findOrFail($request->id);
            $lockedRequest->loadMissing('currentVersion');

            if ($lockedRequest->status !== RequestStatus::Draft || ! $lockedRequest->currentVersion) {
                throw ValidationException::withMessages([
                    'document' => 'Only a draft request with a current version can recover a missing preview.',
                ]);
            }

            $existing = GeneratedDocument::query()
                ->where('request_version_id', $lockedRequest->currentVersion->id)
                ->where('document_type', 'REQUEST_LETTER')
                ->where('status', 'DRAFT')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing) {
                return ['document' => $existing, 'generated' => false];
            }

            return [
                'document' => $this->requestLetter($lockedRequest, false),
                'generated' => true,
            ];
        }, 3);
    }

    public function borrowerSlip(CustodyTransaction $custody): GeneratedDocument
    {
        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
        ]);

        $this->supersede(
            $custody,
            'BORROWER_SLIP',
            'Replaced by the latest physical preparation copy.'
        );

        return $this->saveHtml(
            'BORROWER_SLIP',
            $this->borrowerSlipHtml($custody),
            $custody->request->currentVersion,
            $custody::class,
            $custody->id,
            'FINAL',
            $custody->custody_no.'-BORROWER-SLIP.pdf',
        );
    }

    private function borrowerSlipHtml(CustodyTransaction $custody, bool $documentShell = true, int $pageNumber = 1, int $pageCount = 1): string
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $releasedBy = $custody->released_by_user_id
            ? User::find($custody->released_by_user_id)
            : null;

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img class="borrower-logo" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="seal">CSPC</div>';

        $itemRows = '';
        $itemNo = 1;
        foreach ($custody->lines as $line) {
            $itemRows .= '<tr>'
                .'<td class="item-number">'.e((string) $itemNo).'</td>'
                .'<td>'.e($line->requestItem->description_snapshot).'</td>'
                .'<td class="numeric">'.e((string) ($line->approved_quantity + 0)).'</td>'
                .'<td class="numeric final-issued">'.e((string) ($line->quantity_to_receive + 0)).'</td>'
                .'<td class="unit-cell">'.e($line->requestItem->unit_snapshot).'</td>'
                .'<td class="use-cell">'.e(str_replace('_', '-', $line->requestItem->use_location)).'</td>'
                .'<td class="status-cell">'.e(str_replace('_', ' ', $line->item_status)).'</td>'
                .'</tr>';
            $itemNo++;
        }

        if ($itemRows === '') {
            $itemRows = '<tr><td colspan="7" class="empty-cell">No prepared custody item is recorded for this transaction.</td></tr>';
        }

        $signatureVisual = '<div class="signature-placeholder">Handwritten signature on printed copy</div>';

        $slipStatus = $custody->released_at
            ? 'Released'
            : ($custody->acknowledged_at
                ? 'Acknowledged'
                : ($custody->prepared_at ? 'Prepared' : 'Preparation Pending'));

        $releaseOfficer = $releasedBy?->full_name ?: 'Pending physical release';
        $releaseTime = $custody->released_at
            ? ($this->formalDateTime($custody->released_at) ?? '')
            : 'Pending';

        $body = '<section class="official borrower-slip">'
            .'<table class="borrower-header" role="presentation">'
                .'<colgroup><col style="width:62px"><col></colgroup>'
                .'<tr>'
                    .'<td class="borrower-header-logo-cell">'.$logo.'</td>'
                    .'<td class="borrower-header-copy">'
                        .'<strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>'
                        .'<span>Supply and Property Management Unit</span>'
                    .'</td>'
                .'</tr>'
            .'</table>'

            .'<div class="borrower-title-block">'
                .'<h1>BORROWER\'S SLIP</h1>'
                .'<div class="borrower-meta">'
                    .'<span><b>Custody No.</b> '.e($custody->custody_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Request No.</b> '.e((string) $custody->request->request_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Status</b> '.e($slipStatus).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Issued</b> '.e($this->formalDateTime(now()) ?? '').'</span>'
                .'</div>'
            .'</div>'

            .'<div class="borrower-section-title">Custody / Borrower Information</div>'
            .'<table class="borrower-info-grid">'
                .'<tr>'
                    .'<td><span class="field-label">Borrower</span><span class="field-value">'.e($borrower->full_name).'</span></td>'
                    .'<td><span class="field-label">Custody No.</span><span class="field-value">'.e($custody->custody_no).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Purpose / Event</span><span class="field-value">'.e((string) $version?->purpose_event).'</span></td>'
                    .'<td><span class="field-label">Location</span><span class="field-value">'.e((string) $version?->location).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Release Schedule</span><span class="field-value">'.e($this->formalDateTime($custody->scheduled_release_at) ?? 'Not recorded').'</span></td>'
                    .'<td><span class="field-label">Return Deadline</span><span class="field-value">'.e($this->formalDateTime($custody->due_at) ?? 'Not recorded').'</span></td>'
                .'</tr>'
            .'</table>'

            .'<p class="borrower-intro">This slip records the approved quantities prepared for custody release. The final issued quantities shown below are protected custody fields and form the basis of the borrower acknowledgement.</p>'

            .'<div class="borrower-section-title">Approved Items / Final Issued Quantities</div>'
            .'<table class="borrower-items-table">'
                .'<thead><tr>'
                    .'<th class="item-number">No.</th>'
                    .'<th>Item / Description</th>'
                    .'<th class="numeric">Approved</th>'
                    .'<th class="numeric final-issued">Final Issued</th>'
                    .'<th class="unit-cell">Unit</th>'
                    .'<th class="use-cell">Use Location</th>'
                    .'<th class="status-cell">Status</th>'
                .'</tr></thead>'
                .'<tbody>'.$itemRows.'</tbody>'
            .'</table>'

            .'<div class="borrower-section-title">Borrower Acknowledgement</div>'
            .'<p class="borrower-certification">I acknowledge and accept accountability for the actual quantities prepared and released under this custody transaction, and I agree to return the listed property by the recorded deadline in accordance with the approved borrowing request and SPMU custody requirements.</p>'

            .'<table class="borrower-ack-table" role="presentation"><tr>'
                .'<td class="ack-spacer"></td>'
                .'<td class="ack-block">'
                    .'<div class="ack-caption">Acknowledged and accepted by:</div>'
                    .'<div class="signature-space">'.$signatureVisual.'</div>'
                    .'<div class="signature-name">'.e($borrower->full_name).'</div>'
                    .'<div class="signature-role">Accountable Borrower</div>'
                    .'<div class="signature-subrole">Borrower</div>'
                    .'<div class="signature-date">'.($custody->acknowledged_at ? 'Acknowledged on '.e($this->formalDateTime($custody->acknowledged_at) ?? '') : 'Acknowledgement pending').'</div>'
                    .'<div class="signature-integrity">System acknowledgement is recorded by authenticated user and timestamp; no electronic signature is applied.</div>'
                .'</td>'
            .'</tr></table>'

            .'<div class="borrower-section-title">SPMU Release Control</div>'
            .'<table class="borrower-release-table">'
                .'<tr>'
                    .'<th>SPMU Releasing Officer</th><td>'.e($releaseOfficer).'</td>'
                    .'<th>Physical Release</th><td>'.e($releaseTime).'</td>'
                .'</tr>'
            .'</table>'

            .'<footer class="borrower-footer"><span>Controlled document | SPMU-ACPMP | Official operational time: Asia/Manila</span><span>Page '.e((string) $pageNumber).' of '.e((string) $pageCount).'</span></footer>'
        .'</section>';

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
    }

    public function conditionalForm(CustodyTransaction $custody, string $type): GeneratedDocument
    {
        $type = strtoupper(trim($type));

        $custody->loadMissing([
            'request.borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
            'gatePass.preparedVerifier',
            'gatePass.approver',
            'gatePass.delegation',
            'borrower',
        ]);

        $hasOffCampusProperty = $custody->lines->contains(
            fn ($line) =>
                $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->quantity_to_receive > 0
        );

        $hasLaundryProperty = $custody->lines->contains(
            fn ($line) =>
                (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        if ($type === 'GATE_PASS' && ! $hasOffCampusProperty) {
            throw ValidationException::withMessages([
                'document' => 'A Gate Pass is generated only when the custody includes off-campus property.',
            ]);
        }

        if ($type === 'LAUNDRY_FORM' && ! $hasLaundryProperty) {
            throw ValidationException::withMessages([
                'document' => 'A Laundry Form is generated only when the custody includes laundry-required property.',
            ]);
        }

        if (! in_array($type, ['GATE_PASS', 'LAUNDRY_FORM'], true)) {
            throw ValidationException::withMessages([
                'document' => 'Unsupported physical custody form type.',
            ]);
        }

        $this->supersede(
            $custody,
            $type,
            'Replaced by the latest generated physical form.'
        );

        if ($type === 'LAUNDRY_FORM') {
            return $this->saveHtml(
                'LAUNDRY_FORM',
                $this->laundryFormHtml($custody),
                $custody->request->currentVersion,
                $custody::class,
                $custody->id,
                'FINAL',
                $custody->custody_no.'-LAUNDRY_FORM.pdf',
            );
        }

        return $this->saveHtml(
            'GATE_PASS',
            $this->gatePassHtml($custody),
            $custody->request->currentVersion,
            $custody::class,
            $custody->id,
            'FINAL',
            $custody->custody_no.'-GATE_PASS.pdf',
        );
    }

    private function gatePassHtml(CustodyTransaction $custody, bool $documentShell = true, int $pageNumber = 1, int $pageCount = 1): string
    {
        $version = $custody->request->currentVersion;
        $borrower = $custody->request->borrower;
        $gatePass = $custody->gatePass;

        /*
         * Gate Pass presentation data.
         *
         * Keep this document fully physical/wet-signature based.
         * The logo is embedded only for PDF rendering and the item rows
         * contain the approved off-campus custody lines.
         */
        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img class="gate-logo" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="seal">CSPC</div>';

        $offCampusLines = $custody->lines->filter(
            fn ($line) =>
                $line->requestItem?->use_location === 'OFF_CAMPUS'
        );

        $itemRows = '';
        $itemNo = 1;

        foreach ($offCampusLines as $line) {
            $itemRows .= '<tr>'
                .'<td class="item-number">'.e((string) $itemNo).'</td>'
                .'<td>'.e((string) $line->requestItem?->description_snapshot).'</td>'
                .'<td class="numeric">'.e((string) ($line->quantity_to_receive + 0)).'</td>'
                .'<td class="unit-cell">'.e((string) $line->requestItem?->unit_snapshot).'</td>'
                .'<td class="use-cell">OFF-CAMPUS</td>'
                .'</tr>';

            $itemNo++;
        }

        if ($itemRows === '') {
            $itemRows = '<tr><td colspan="5" class="empty-cell">No off-campus custody item is recorded for this transaction.</td></tr>';
        }

        $gateStatus = $gatePass?->status === 'VERIFIED'
            ? 'Accomplished and Verified'
            : ($custody->released_at
                ? 'For Accomplished Scan'
                : 'For Physical Signatures');

        $physicalSignatureLine =
            '<div class="signature-placeholder">Handwritten signature</div>';

        $body = '<section class="official gate-pass">'
            .'<table class="gate-header" role="presentation">'
                .'<colgroup><col style="width:62px"><col></colgroup>'
                .'<tr>'
                    .'<td class="gate-header-logo-cell">'.$logo.'</td>'
                    .'<td class="gate-header-copy">'
                        .'<strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>'
                        .'<span>Supply and Property Management Unit</span>'
                    .'</td>'
                .'</tr>'
            .'</table>'

            .'<div class="gate-title-block">'
                .'<h1>GATE PASS</h1>'
                .'<div class="gate-meta">'
                    .'<span><b>Custody No.</b> '.e($custody->custody_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Request No.</b> '.e((string) $custody->request->request_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Status</b> '.e($gateStatus).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Issued</b> '.e($this->formalDateTime(now()) ?? '').'</span>'
                .'</div>'
            .'</div>'

            .'<div class="gate-section-title">Bearer / Movement Information</div>'
            .'<table class="gate-info-grid">'
                .'<tr>'
                    .'<td><span class="field-label">Borrower / Bearer</span><span class="field-value">'.e($borrower->full_name).'</span></td>'
                    .'<td><span class="field-label">Custody No.</span><span class="field-value">'.e($custody->custody_no).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Purpose / Event</span><span class="field-value">'.e((string) $version?->purpose_event).'</span></td>'
                    .'<td><span class="field-label">Destination / Location</span><span class="field-value">'.e((string) $version?->location).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Release Schedule</span><span class="field-value">'.e($this->formalDateTime($custody->scheduled_release_at) ?? 'Not recorded').'</span></td>'
                    .'<td><span class="field-label">Return Deadline</span><span class="field-value">'.e($this->formalDateTime($custody->due_at) ?? 'Not recorded').'</span></td>'
                .'</tr>'
            .'</table>'

            .'<p class="gate-intro">This Gate Pass covers only the approved property listed below for authorized off-campus movement. Required signatures are completed by hand on the printed form.</p>'

            .'<div class="gate-section-title">Approved Items for Off-Campus Movement</div>'
            .'<table class="gate-items-table">'
                .'<thead><tr>'
                    .'<th class="item-number">No.</th>'
                    .'<th>Item / Description</th>'
                    .'<th class="numeric">Quantity</th>'
                    .'<th class="unit-cell">Unit</th>'
                    .'<th class="use-cell">Use Location</th>'
                .'</tr></thead>'
                .'<tbody>'.$itemRows.'</tbody>'
            .'</table>'

            .'<div class="gate-section-title">SPMU Verification and Approval</div>'
            .'<p class="gate-certification">Complete the signatures below by hand on the printed Gate Pass. The accomplished scan is returned to SPMU for recording and verification.</p>'

            .'<table class="gate-signatures" role="presentation"><tr>'
                .'<td>'
                    .'<div class="signature-label">Verified By - SPMU Action Officer</div>'
                    .'<div class="signature-space">'.$physicalSignatureLine.'</div>'
                    .'<div class="signature-name">SPMU ACTION OFFICER</div>'
                    .'<div class="signature-role">Supply and Property Management Unit</div>'
                    .'<div class="signature-date">Sign and date on the printed form</div>'
                .'</td>'
                .'<td>'
                    .'<div class="signature-label">Approved By - SPMU Head</div>'
                    .'<div class="signature-space">'.$physicalSignatureLine.'</div>'
                    .'<div class="signature-name">SPMU HEAD</div>'
                    .'<div class="signature-role">Supply and Property Management Unit</div>'
                    .'<div class="signature-date">Sign and date on the printed form</div>'
                .'</td>'
            .'</tr></table>'

            .'<div class="gate-section-title">Guard / Exit Control</div>'
            .'<p class="gate-guard-note">To be completed by the guard on duty after the authorized property is physically presented for exit.</p>'
            .'<table class="gate-guard-table">'
                .'<tr>'
                    .'<th>Released By</th><td class="write-line"></td>'
                    .'<th>Date</th><td class="write-line"></td>'
                .'</tr>'
                .'<tr>'
                    .'<th>Time</th><td class="write-line"></td>'
                    .'<th>Signature</th><td class="write-line"></td>'
                .'</tr>'
            .'</table>'

            .'<p class="gate-note"><strong>Document handling:</strong> Return the signed original to SPMU for recording. The borrower may upload the signed scan; any authorized SPMU fallback upload remains auditable.</p>'

            .'<footer class="gate-footer"><span>Controlled document | SPMU-ACPMP | Official operational time: Asia/Manila</span><span>Page '.e((string) $pageNumber).' of '.e((string) $pageCount).'</span></footer>'
        .'</section>';

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
    }

    private function laundryFormHtml(
        CustodyTransaction $custody,
        bool $documentShell = true,
        int $pageNumber = 1,
        int $pageCount = 1
    ): string {
        $version = $custody->request->currentVersion;
        $borrower = $custody->borrower;

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img class="laundry-logo" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="seal">CSPC</div>';

        $laundryLines = $custody->lines->filter(
            fn ($line) =>
                (bool) $line->requestItem->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        $itemRows = '';
        $itemNo = 1;

        foreach ($laundryLines as $line) {
            $itemRows .= '<tr>'
                .'<td class="item-number">'.e((string) $itemNo).'</td>'
                .'<td>'.e($line->requestItem->description_snapshot).'</td>'
                .'<td class="numeric">'.e((string) ($line->quantity_to_receive + 0)).'</td>'
                .'<td class="unit-cell">'.e($line->requestItem->unit_snapshot).'</td>'
                .'<td class="write-line"></td>'
                .'<td class="write-line"></td>'
                .'<td class="write-line"></td>'
                .'<td class="write-line"></td>'
                .'</tr>';

            $itemNo++;
        }

        if ($itemRows === '') {
            $itemRows = '<tr><td colspan="8" class="empty-cell">No laundry-required item is recorded for this custody transaction.</td></tr>';
        }

        $body = '<section class="official laundry-form">'
            .'<table class="laundry-header" role="presentation">'
                .'<colgroup><col style="width:62px"><col></colgroup>'
                .'<tr>'
                    .'<td class="laundry-header-logo-cell">'.$logo.'</td>'
                    .'<td class="laundry-header-copy">'
                        .'<strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>'
                        .'<span>Supply and Property Management Unit</span>'
                    .'</td>'
                .'</tr>'
            .'</table>'

            .'<div class="laundry-title-block">'
                .'<h1>LAUNDRY SERVICE FORM</h1>'
                .'<div class="laundry-meta">'
                    .'<span><b>Custody No.</b> '.e($custody->custody_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Request No.</b> '.e((string) $custody->request->request_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Document</b> Physical Working Form</span>'
                .'</div>'
            .'</div>'

            .'<div class="laundry-section-title">Borrowing Information</div>'
            .'<table class="laundry-info-grid">'
                .'<tr>'
                    .'<td><span class="field-label">Borrower</span><span class="field-value">'.e($borrower->full_name).'</span></td>'
                    .'<td><span class="field-label">Custody No.</span><span class="field-value">'.e($custody->custody_no).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Purpose / Event</span><span class="field-value">'.e((string) $version?->purpose_event).'</span></td>'
                    .'<td><span class="field-label">Location</span><span class="field-value">'.e((string) $version?->location).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Issued / Approved Linen</span><span class="field-value">Use the item table below</span></td>'
                    .'<td><span class="field-label">Return Deadline</span><span class="field-value">'.e($this->formalDateTime($custody->due_at) ?? 'Not recorded').'</span></td>'
                .'</tr>'
            .'</table>'

            .'<p class="laundry-intro"><strong>Borrower instruction:</strong> After use, bring all used linen and this printed Laundry Form to the Laundry Area. Sign the Borrower Turnover portion before handing the linen and form to the Laundry Worker. After turnover, no borrower pickup of cleaned linen is required; Laundry will return the cleaned linen directly to SPMU.</p>'

            .'<div class="laundry-section-title">Borrower Turnover Certification</div>'
            .'<table class="laundry-worker-table">'
                .'<tr><th>Borrower</th><td class="write-line">'.e($borrower->full_name).'</td><th>Signature</th><td class="write-line"></td></tr>'
                .'<tr><th>Turnover Date / Time</th><td class="write-line"></td><th>Quantity Turned Over</th><td class="write-line"></td></tr>'
                .'<tr><th>Borrower Remarks</th><td colspan="3" class="write-line"></td></tr>'
            .'</table>'

            .'<div class="laundry-section-title">Laundry Worker - Receive, Inspect, and Complete</div>'
            .'<table class="laundry-items-table">'
                .'<thead><tr>'
                    .'<th class="item-number">No.</th>'
                    .'<th>Item / Description</th>'
                    .'<th class="numeric">Issued Qty</th>'
                    .'<th class="unit-cell">Unit</th>'
                    .'<th>Received Qty</th>'
                    .'<th>Condition / Issue</th>'
                    .'<th>Completed Qty</th>'
                    .'<th>Remarks</th>'
                .'</tr></thead>'
                .'<tbody>'.$itemRows.'</tbody>'
            .'</table>'

            .'<div class="laundry-section-title">Laundry Worker Certification</div>'
            .'<table class="laundry-worker-table">'
                .'<tr><th>Laundry Worker</th><td class="write-line"></td><th>Signature</th><td class="write-line"></td></tr>'
                .'<tr><th>Received Date / Time</th><td class="write-line"></td><th>Completed Date / Time</th><td class="write-line"></td></tr>'
                .'<tr><th>General Remarks</th><td colspan="3" class="write-line"></td></tr>'
            .'</table>'

            .'<div class="laundry-section-title">SPMU Final Receiving / Acceptance</div>'
            .'<table class="laundry-worker-table">'
                .'<tr><th>SPMU Head / Authorized Signatory</th><td class="write-line"></td><th>Signature</th><td class="write-line"></td></tr>'
                .'<tr><th>Final Received Date / Time</th><td class="write-line"></td><th>Final Quantity Accepted</th><td class="write-line"></td></tr>'
                .'<tr><th>Final Condition / Remarks</th><td colspan="3" class="write-line"></td></tr>'
            .'</table>'

            .'<p class="laundry-note"><strong>System recording:</strong> Laundry Worker records the actual quantity received and the laundry completion details in the Laundry portal. After processing, the Laundry Worker brings the cleaned linen and this same physical form directly to SPMU for final quantity/condition inspection and SPMU signature.</p>'

            .'<p class="laundry-note"><strong>Final upload:</strong> After SPMU signs the final receiving/acceptance portion, the form is returned to the Laundry Worker. The Laundry Worker scans/uploads the fully accomplished form. Only that final upload settles the Laundry transaction; the Borrower does not collect the cleaned linen or re-enter laundry quantities in the system.</p>'

            .'<footer class="laundry-footer"><span>Controlled physical working form | SPMU-ACPMP | Official operational time: Asia/Manila</span><span>Page '.e((string) $pageNumber).' of '.e((string) $pageCount).'</span></footer>'
        .'</section>';

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
    }

    private function officialPacketRequestHtml(CustodyTransaction $custody, int $pageNumber, int $pageCount): string
    {
        $request = $custody->request;
        $version = $request->currentVersion;
        $borrower = $request->borrower;

        $logoPath = resource_path('images/cspc-logo-print.jpg');
        $logo = is_file($logoPath)
            ? '<img class="packet-logo" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)).'" alt="CSPC logo">'
            : '<div class="seal">CSPC</div>';

        $signatureCells = [[
            'label' => 'Accountable Borrower',
            'name' => $borrower->full_name,
            'role' => 'Borrower',
            'time' => $version?->submitted_at,
            'visual' => '<div class="signature-placeholder">See uploaded wet-signed request letter</div>',
        ]];

        foreach (
            ($version?->approvalSteps ?? collect())
                ->filter(fn ($step) => $step->stage_code->value === 'SPMU')
                ->sortBy('sequence_no')
            as $step
        ) {
            $signatureCells[] = [
                'label' => 'SPMU Verification',
                'name' => $step->approver?->full_name ?: 'Authorized SPMU reviewer',
                'role' => UserRole::Spmu->label(),
                'time' => $step->decided_at,
                'visual' => '<div class="signature-placeholder">System verification record</div>',
            ];
        }

        $signatureRows = '';
        foreach (array_chunk($signatureCells, 2) as $row) {
            $signatureRows .= '<tr>';
            foreach ($row as $signature) {
                $signatureRows .= '<td>'
                    .'<div class="packet-signature-label">'.e($signature['label']).'</div>'
                    .'<div class="packet-signature-space">'.$signature['visual'].'</div>'
                    .'<div class="packet-signature-name">'.e(strtoupper((string) $signature['name'])).'</div>'
                    .'<div class="packet-signature-role">'.e((string) $signature['role']).'</div>'
                    .'<div class="packet-signature-date">'.e($this->formalDateTime($signature['time']) ?? 'Date unavailable').'</div>'
                .'</td>';
            }
            if (count($row) === 1) {
                $signatureRows .= '<td></td>';
            }
            $signatureRows .= '</tr>';
        }

        return '<section class="official packet-request">'
            .'<table class="packet-header" role="presentation">'
                .'<colgroup><col style="width:62px"><col></colgroup>'
                .'<tr>'
                    .'<td class="packet-header-logo-cell">'.$logo.'</td>'
                    .'<td class="packet-header-copy">'
                        .'<strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>'
                        .'<span>Supply and Property Management Unit</span>'
                    .'</td>'
                .'</tr>'
            .'</table>'

            .'<div class="packet-title-block">'
                .'<h1>BORROWING REQUEST LETTER</h1>'
                .'<div class="packet-meta">'
                    .'<span><b>Request No.</b> '.e((string) $request->request_no).'</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Status</b> Fully Approved</span>'
                    .'<span class="meta-separator">|</span>'
                    .'<span><b>Custody No.</b> '.e($custody->custody_no).'</span>'
                .'</div>'
            .'</div>'

            .'<div class="packet-section-title">Borrower / Event Information</div>'
            .'<table class="packet-info-grid">'
                .'<tr>'
                    .'<td><span class="field-label">Borrower</span><span class="field-value">'.e($borrower->full_name).'</span></td>'
                    .'<td><span class="field-label">Purpose / Event</span><span class="field-value">'.e((string) $version?->purpose_event).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Location</span><span class="field-value">'.e((string) $version?->location).'</span></td>'
                    .'<td><span class="field-label">Custody No.</span><span class="field-value">'.e($custody->custody_no).'</span></td>'
                .'</tr>'
                .'<tr>'
                    .'<td><span class="field-label">Needed From</span><span class="field-value">'.e($this->formalDateTime($version?->needed_from) ?? 'Not recorded').'</span></td>'
                    .'<td><span class="field-label">Return Deadline</span><span class="field-value">'.e($this->formalDateTime($custody->due_at) ?? 'Not recorded').'</span></td>'
                .'</tr>'
            .'</table>'

            .'<p class="packet-note">This page forms part of the official custody packet. The approved request, borrower record, and SPMU verification history are preserved in SPMU-ACPMP and summarized below for custody reference.</p>'

            .'<div class="packet-section-title">Approval Record</div>'
            .'<table class="packet-signature-grid" role="presentation">'.$signatureRows.'</table>'

            .'<footer class="packet-footer"><span>Controlled document | SPMU-ACPMP | Official operational time: Asia/Manila</span><span>Page '.e((string) $pageNumber).' of '.e((string) $pageCount).'</span></footer>'
        .'</section>';
    }

    public function replaceConditionalForm(CustodyTransaction $custody, string $type): GeneratedDocument
    {
        $this->supersede($custody, $type, 'Replaced after a controlled custody or document update.');
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
            'request.borrower', 'request.currentVersion.approvalSteps.approver',
            'lines.requestItem.inventoryItem', 'gatePass.preparedVerifier', 'gatePass.approver',
        ]);
        if (! $custody->acknowledged_at) {
            return null;
        }
        $hasGatePass = $custody->lines->contains(fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS');
        $hasLaundry = $custody->lines->contains(fn ($line) => (bool) $line->requestItem->inventoryItem?->laundry_required);

        $this->supersede($custody, 'OFFICIAL_FORM_PACKET', 'Replaced by the latest approved packet.');
        $totalPages = 2 + ($hasGatePass ? 1 : 0) + ($hasLaundry ? 1 : 0);
        $nextPageNumber = 1;
        $pages = [
            ['__html' => $this->officialPacketRequestHtml($custody, $nextPageNumber++, $totalPages)],
            ['__html' => $this->borrowerSlipHtml($custody, false, $nextPageNumber++, $totalPages)],
        ];
        if ($hasGatePass) {
            $pages[] = ['__html' => $this->gatePassHtml($custody, false, $nextPageNumber++, $totalPages)];
        }
        if ($hasLaundry) {
            $pages[] = ['__html' => $this->laundryFormHtml($custody, false, $nextPageNumber++, $totalPages)];
        }

        $htmlPages = [];
        foreach ($pages as $index => $page) {
            if (isset($page['__html'])) {
                $htmlPages[] = $page['__html'];

                continue;
            }

            $htmlPages[] = $this->officialHtml((string) ($page[3] ?? $page[1] ?? 'Official Form'), $page, false);
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

    private function saveHtml(string $type, string $html, ?RequestVersion $version, string $subjectType, int $subjectId, string $status, string $filename, bool $pageNumbers = false): GeneratedDocument
    {
        $bytes = $this->pdf->html($html, $pageNumbers);
        $file = $this->files->storeBytes($bytes, 'generated-documents', $filename, 'application/pdf', 'pdf', 'CONTROLLED_DOCUMENT');

        return GeneratedDocument::query()->create([
            'stored_file_id' => $file->id, 'request_version_id' => $version?->id,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'document_no' => strtoupper($type).'-'.now()->format('YmdHis').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'document_type' => $type, 'version_no' => $version?->version_no ?? 1, 'sha256' => $file->sha256,
            'status' => $status, 'generated_at' => now(),
        ]);
    }

    private function officialHtml(
        string $title,
        array $lines,
        bool $documentShell = true
    ): string {
        $body = '<section class="official"><header><div class="seal">CSPC</div><div><strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong><span>Supply and Property Management Unit</span></div></header><h1>'.e($title).'</h1><div class="lines">';

        foreach ($lines as $line) {
            $body .= $line === ''
                ? '<div class="spacer"></div>'
                : '<p>'.e($line).'</p>';
        }

        $body .= '</div><footer>Controlled document · Asia/Manila · Operational records are maintained in SPMU-ACPMP</footer></section>';

        return $documentShell
            ? '<!doctype html><html><head>'.$this->officialCss().'</head><body>'.$body.'</body></html>'
            : $body;
    }

    private function formalDateTime(?CarbonInterface $date): ?string
    {
        if (! $date) {
            return null;
        }

        $localized = CarbonImmutable::instance($date)->setTimezone('Asia/Manila');

        return str_replace([' am', ' pm'], [' a.m.', ' p.m.'], $localized->format('j F Y, g:i a'));
    }

    private function officialCss(): string
    {
        return '<style>
            @page{margin:34px 42px}
            *{box-sizing:border-box}
            body{margin:0;color:#16314c;font-family:DejaVu Sans,Arial,sans-serif;font-size:10px}
            .official{min-height:720px;position:relative;padding-bottom:36px}
            .official header{display:flex;align-items:center;gap:12px;padding-bottom:12px;border-bottom:2px solid #0b3156}
            .seal{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:10px;background:#0b3156;color:#fff;font-weight:bold}
            .official header strong,.official header span{display:block}
            .official header strong{font-size:13px}
            .official header span{color:#60758a}
            .official h1{text-align:center;margin:22px 0 18px;color:#0b3156;font-size:17px;text-transform:uppercase}
            .lines p{margin:0 0 6px;padding:0 0 4px;border-bottom:1px solid #e1e8ef}
            .spacer{height:8px}
            .signature-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:18px}
            .signature-block{min-height:125px;padding:10px;border:1px solid #cdd9e5;border-radius:8px;text-align:center}
            .signature-block small,.signature-block strong,.signature-block span,.signature-block code{display:block}
            .signature-block small{color:#60758a;text-transform:uppercase}
            .signature-block span,.signature-block code{font-size:8px;color:#60758a}
            .signature-image{display:block;max-width:170px;max-height:60px;margin:5px auto}
            .signature-missing{height:55px;padding-top:20px;color:#8b97a4}
            .official footer{position:absolute;bottom:0;left:0;right:0;padding-top:8px;border-top:1px solid #dbe3eb;color:#6c7d8d;text-align:center;font-size:8px}
            .page-break{page-break-after:always}

            .packet-request{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .packet-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .packet-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .packet-header-logo-cell{width:62px;padding-right:10px}
            .packet-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .packet-header-copy{text-align:left}
            .packet-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .packet-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .packet-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .packet-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .packet-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .packet-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .packet-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .packet-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .packet-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .packet-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .packet-note{margin:2px 0 9px;font-size:8.7px;line-height:1.42;color:#526272;text-align:justify}
            .packet-signature-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:2px;page-break-inside:avoid}
            .packet-signature-grid td{width:50%;padding:7px 18px 8px;border:0;vertical-align:top;text-align:center}
            .packet-signature-grid tr+tr td{padding-top:10px}
            .packet-signature-label{font-size:8px;font-weight:bold;text-transform:uppercase;color:#0b3156;letter-spacing:.18px}
            .packet-signature-space{height:48px;padding-top:2px}
            .packet-signature-name{padding-top:3px;border-top:1px solid #7e8d9a;font-size:9.6px;font-weight:bold;color:#24384b}
            .packet-signature-role{margin-top:2px;font-size:7.9px;color:#45586b}
            .packet-signature-date{margin-top:3px;font-size:7.4px;color:#5f6f7e}
            .packet-signature-integrity{margin-top:2px;font-size:6.7px;color:#7a8793}
            .packet-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}

            .gate-pass{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .gate-pass .gate-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .gate-pass .gate-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .gate-pass .gate-header-logo-cell{width:62px;padding-right:10px}
            .gate-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .gate-pass .gate-header-copy{width:auto;text-align:left}
            .gate-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .gate-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .gate-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .gate-pass .gate-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .gate-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .gate-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .gate-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .gate-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .gate-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .gate-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .gate-intro{margin:1px 0 10px;color:#2e3d4c;font-size:9.5px;line-height:1.48;text-align:justify}
            .gate-items-table,.gate-guard-table{width:100%;border-collapse:collapse;table-layout:fixed}
            .gate-items-table th,.gate-items-table td,.gate-guard-table th,.gate-guard-table td{border:1px solid #8d9aa6;padding:5px 7px;vertical-align:middle}
            .gate-items-table thead th{background:#e9edf1;color:#273b4f;font-size:8px;font-weight:bold;text-transform:uppercase;letter-spacing:.2px;text-align:center}
            .gate-items-table thead th:nth-child(2){text-align:left}
            .gate-items-table td{font-size:9.5px;color:#26394d}
            .gate-items-table .item-number{width:7%;text-align:center}
            .gate-items-table th:nth-child(2){width:49%}
            .gate-items-table .numeric{width:13%;text-align:center}
            .gate-items-table .unit-cell{width:13%;text-align:center}
            .gate-items-table .use-cell{width:18%;text-align:center}
            .gate-certification{margin:0 0 7px;font-size:9.5px;line-height:1.48;color:#2e3d4c;text-align:justify}
            .gate-signatures{width:100%;border-collapse:collapse;table-layout:fixed;margin:5px 0 2px;page-break-inside:avoid}
            .gate-signatures td{width:50%;padding:5px 15px 0;border:0;text-align:center;vertical-align:top}
            .gate-signatures td:first-child{padding-left:18px;padding-right:20px}
            .gate-signatures td:last-child{padding-left:20px;padding-right:18px}
            .gate-guard-note{margin:0 0 5px;font-size:8.5px;line-height:1.4;color:#596979}
            .gate-guard-table{margin-top:1px;page-break-inside:avoid}
            .gate-guard-table th{width:15%;background:#f3f5f7;color:#44576a;text-align:left;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px}
            .gate-guard-table td{width:35%;height:31px;font-size:9px;color:#26394d}
            .gate-note{margin:9px 0 0;padding:0;border:0;background:transparent;font-size:7.8px;line-height:1.4;color:#667481}
            .gate-pass .gate-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}

            .borrower-slip{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .borrower-slip .borrower-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .borrower-slip .borrower-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .borrower-slip .borrower-header-logo-cell{width:62px;padding-right:10px}
            .borrower-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .borrower-slip .borrower-header-copy{width:auto;text-align:left}
            .borrower-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .borrower-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .borrower-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .borrower-slip .borrower-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .borrower-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .borrower-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .borrower-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .borrower-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .borrower-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .borrower-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .borrower-intro{margin:1px 0 10px;color:#2e3d4c;font-size:9.5px;line-height:1.48;text-align:justify}
            .borrower-items-table{width:100%;border-collapse:collapse;table-layout:fixed}
            .borrower-items-table th,.borrower-items-table td{border:1px solid #8d9aa6;padding:5px 6px;vertical-align:middle}
            .borrower-items-table thead th{background:#e9edf1;color:#273b4f;font-size:7.6px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px;text-align:center}
            .borrower-items-table thead th:nth-child(2){text-align:left}
            .borrower-items-table td{font-size:8.9px;color:#26394d}
            .borrower-items-table .item-number{width:5.5%;text-align:center}
            .borrower-items-table th:nth-child(2){width:35%}
            .borrower-items-table .numeric{width:10%;text-align:center}
            .borrower-items-table .final-issued{width:11.5%}
            .borrower-items-table .unit-cell{width:10%;text-align:center}
            .borrower-items-table .use-cell{width:14%;text-align:center}
            .borrower-items-table .status-cell{width:14%;text-align:center;text-transform:uppercase}
            .borrower-certification{margin:0 0 5px;font-size:9.5px;line-height:1.48;color:#2e3d4c;text-align:justify}
            .borrower-ack-table{width:100%;border-collapse:collapse;table-layout:fixed;margin:3px 0 2px;page-break-inside:avoid}
            .borrower-ack-table td{border:0;vertical-align:top}
            .borrower-ack-table .ack-spacer{width:42%}
            .borrower-ack-table .ack-block{width:58%;padding:2px 18px 0;text-align:center}
            .ack-caption{margin-bottom:2px;font-size:8.5px;color:#566779}
            .borrower-release-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:2px;page-break-inside:avoid}
            .borrower-release-table th,.borrower-release-table td{border:1px solid #8d9aa6;padding:6px 7px;vertical-align:middle}
            .borrower-release-table th{width:18%;background:#f3f5f7;color:#44576a;text-align:left;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px}
            .borrower-release-table td{width:32%;font-size:9px;color:#26394d}
            .borrower-slip .borrower-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}

            .laundry-form{color:#24364a;font-size:9.7px;line-height:1.42;padding-bottom:40px}
            .laundry-form .laundry-header{width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 10px;border-bottom:1.5px solid #0b3156}
            .laundry-form .laundry-header td{border:0;padding:0 0 10px;vertical-align:middle}
            .laundry-form .laundry-header-logo-cell{width:62px;padding-right:10px}
            .laundry-logo{display:block;width:52px;height:52px;object-fit:contain;margin:0}
            .laundry-form .laundry-header-copy{width:auto;text-align:left}
            .laundry-header-copy strong{display:block;font-family:DejaVu Serif,serif;font-size:14.2px;line-height:1.12;letter-spacing:.18px;color:#0b3156}
            .laundry-header-copy span{display:block;margin-top:3px;font-size:8.8px;font-weight:bold;color:#33495e}
            .laundry-title-block{text-align:center;padding:14px 0 9px;border-bottom:1px solid #c7cfd6}
            .laundry-form .laundry-title-block h1{margin:0 0 8px;color:#0b3156;font-family:DejaVu Serif,serif;font-size:18px;line-height:1.1;letter-spacing:.75px;font-weight:bold;text-transform:uppercase}
            .laundry-meta{font-size:7.8px;color:#5d6975;white-space:nowrap}
            .laundry-meta b{color:#34485c;text-transform:uppercase;font-size:7.1px;letter-spacing:.12px}
            .meta-separator{display:inline-block;margin:0 7px;color:#8a98a5}
            .laundry-section-title{margin:12px 0 7px;padding:0 0 4px;border-bottom:1.2px solid #73879a;background:transparent;color:#0b3156;font-size:9.4px;line-height:1.2;font-weight:bold;text-transform:uppercase;letter-spacing:.35px}
            .laundry-info-grid{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
            .laundry-info-grid td{width:50%;padding:0 20px 8px 0;border:0;vertical-align:top}
            .laundry-info-grid td:nth-child(2){padding-right:0;padding-left:8px}
            .field-label,.field-value{display:block}
            .field-label{margin-bottom:2px;color:#4a5967;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.18px}
            .field-value{color:#26394d;font-size:10px;line-height:1.3}
            .laundry-intro{margin:1px 0 10px;color:#2e3d4c;font-size:9.5px;line-height:1.48;text-align:justify}
            .laundry-items-table,.laundry-worker-table{width:100%;border-collapse:collapse;table-layout:fixed}
            .laundry-items-table th,.laundry-items-table td,.laundry-worker-table th,.laundry-worker-table td{border:1px solid #8d9aa6;padding:5px 7px;vertical-align:middle}
            .laundry-items-table thead th{background:#e9edf1;color:#273b4f;font-size:8px;font-weight:bold;text-transform:uppercase;letter-spacing:.2px;text-align:center}
            .laundry-items-table thead th:nth-child(2){text-align:left}
            .laundry-items-table td{font-size:9.5px;color:#26394d}
            .laundry-items-table .item-number{width:7%;text-align:center}
            .laundry-items-table .numeric{width:13%;text-align:center}
            .laundry-items-table .unit-cell{width:16%;text-align:center}
            .empty-cell{text-align:center;color:#7c8b98;font-style:italic}
            .laundry-certification{margin:0 0 7px;font-size:9.5px;line-height:1.48;color:#2e3d4c;text-align:justify}
            .laundry-signatures{width:100%;border-collapse:collapse;table-layout:fixed;margin:5px 0 2px;page-break-inside:avoid}
            .laundry-signatures td{width:50%;padding:5px 15px 0;border:0;text-align:center;vertical-align:top}
            .laundry-signatures td:first-child{padding-left:18px;padding-right:20px}
            .laundry-signatures td:last-child{padding-left:20px;padding-right:18px}
            .signature-label{font-size:8px;font-weight:bold;text-transform:uppercase;color:#0b3156;letter-spacing:.18px}
            .signature-space{height:58px;padding-top:5px}
            .formal-signature-image{display:block;max-width:175px;max-height:52px;margin:0 auto}
            .signature-placeholder{padding-top:22px;font-size:8.5px;color:#7f8e9b}
            .signature-name{padding-top:3px;border-top:1px solid #7e8d9a;font-size:10px;font-weight:bold;text-transform:uppercase;color:#24384b}
            .signature-role{margin-top:2px;font-size:8.4px;color:#45586b}
            .signature-subrole{margin-top:1px;font-size:8px;color:#45586b}
            .signature-date{margin-top:3px;font-size:7.8px;color:#5f6f7e}
            .signature-integrity,.signature-note{margin-top:2px;font-size:6.9px;color:#7a8793}
            .signature-note{font-style:italic}
            .laundry-worker-table{margin-top:1px;page-break-inside:avoid}
            .laundry-worker-table th{width:18%;background:#f3f5f7;color:#44576a;text-align:left;font-size:7.8px;font-weight:bold;text-transform:uppercase;letter-spacing:.15px}
            .laundry-worker-table td{height:32px;font-size:9px;color:#26394d}
            .write-line{background:#fff}
            .condition-cell{height:34px}
            .check-box{font-family:DejaVu Sans,sans-serif;font-size:12px;vertical-align:-1px}
            .condition-gap{display:inline-block;width:20px}
            .laundry-note{margin:9px 0 0;padding:0;border:0;background:transparent;font-size:7.8px;line-height:1.4;color:#667481}
            .laundry-form .laundry-footer{position:absolute;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding-top:7px;border-top:1px solid #d3d9df;color:#6b7783;text-align:left;font-size:7.2px}
        </style>';
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