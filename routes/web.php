<?php

use App\Http\Controllers\AccountabilityController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BorrowingRequestController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConditionalProcessingController;
use App\Http\Controllers\CustodyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DelegationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LaundryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePictureController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SanctionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TechnicalOperationController;
use App\Http\Controllers\UserAdministrationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});


/*
|--------------------------------------------------------------------------
| EXTERNAL BORROWER SELF-REGISTRATION
|--------------------------------------------------------------------------
|
| Public self-registration is limited to BORROWER_ONLY accounts.
| SPMU borrower verification is handled separately.
|
*/

Route::middleware('guest')->group(function (): void {

    Route::get(
        '/register/external',
        [
            \App\Http\Controllers\Auth\ExternalRegistrationController::class,
            'create'
        ]
    )->name('external.register');


    Route::post(
        '/register/external',
        [
            \App\Http\Controllers\Auth\ExternalRegistrationController::class,
            'store'
        ]
    )
        ->middleware('throttle:5,1')
        ->name('external.register.store');

});

Route::middleware(['auth', 'active'])->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | Dashboard / Authentication
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->name('logout');


    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');


    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'show'])
        ->name('profile.show');

    Route::put('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::get('/profile/picture', [ProfilePictureController::class, 'show'])
        ->name('profile.picture.show');

    Route::post('/profile/picture', [ProfilePictureController::class, 'update'])
        ->name('profile.picture.update');

    Route::delete('/profile/picture', [ProfilePictureController::class, 'destroy'])
        ->name('profile.picture.destroy');

    Route::get('/protected-files/{file}', [DocumentController::class, 'protectedFile'])
        ->name('files.show');


    /*
    |--------------------------------------------------------------------------
    | Inventory - Borrower + SPMU
    |--------------------------------------------------------------------------
    |
    | Borrower:
    | - Read-only inventory visibility
    | - Current borrowable availability
    | - No create / edit / reserve / hold
    |
    | SPMU:
    | - Full operational inventory access
    |
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/inventory', [InventoryController::class, 'index'])
            ->name('inventory.index');

        Route::get('/inventory-availability', [InventoryController::class, 'availabilityData'])
            ->name('inventory.availability');
        Route::get('/inventory/{inventory}', [InventoryController::class, 'show'])
            ->whereNumber('inventory')
            ->name('inventory.show');
    });


    /*
    |--------------------------------------------------------------------------
    | Inventory Management - SPMU Only
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/inventory/create', [InventoryController::class, 'create'])
            ->name('inventory.create');

        Route::post('/inventory', [InventoryController::class, 'store'])
            ->name('inventory.store');

        Route::get('/inventory/{inventory}/edit', [InventoryController::class, 'edit'])
            ->name('inventory.edit');

        Route::put('/inventory/{inventory}', [InventoryController::class, 'update'])
            ->name('inventory.update');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Calendar
    |--------------------------------------------------------------------------
    |
    | Active borrower/SPMU operational calendar only.
    | GSU/VPAF are not borrowing approvers in the current workflow.
    |
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/calendar', [CalendarController::class, 'index'])
            ->name('calendar.index');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Request - Create / Store
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER')->group(function (): void {

        Route::get('/requests/create', [BorrowingRequestController::class, 'create'])
            ->name('requests.create');

        Route::post('/requests', [BorrowingRequestController::class, 'store'])
            ->name('requests.store');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Requests - Active Workflow View
    |--------------------------------------------------------------------------
    |
    | Borrower and SPMU only. GSU/VPAF approval stages are retired from the
    | active workflow; historical database values may remain for compatibility.
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/requests', [BorrowingRequestController::class, 'index'])
            ->name('requests.index');

        Route::get('/requests/{borrowingRequest}', [BorrowingRequestController::class, 'show'])
            ->name('requests.show');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Request - Borrower Actions
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER')->group(function (): void {

        Route::get('/requests/{borrowingRequest}/edit', [BorrowingRequestController::class, 'edit'])
            ->name('requests.edit');

        Route::put('/requests/{borrowingRequest}', [BorrowingRequestController::class, 'update'])
            ->name('requests.update');

        Route::post(
            '/requests/{borrowingRequest}/supporting-documents',
            [BorrowingRequestController::class, 'uploadSupportingDocuments']
        )->name('requests.supporting-documents.store');

        Route::post(
            '/requests/{borrowingRequest}/recover-draft-document',
            [BorrowingRequestController::class, 'recoverDraftDocument']
        )->name('requests.recover-draft-document');

        Route::post('/requests/{borrowingRequest}/submit', [BorrowingRequestController::class, 'submit'])
            ->name('requests.submit');
    });


    /*
    |--------------------------------------------------------------------------
    | Request Cancellation
    |--------------------------------------------------------------------------
    */

    Route::post('/requests/{borrowingRequest}/cancel', [BorrowingRequestController::class, 'cancel'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('requests.cancel');

    Route::post(
        '/requests/{borrowingRequest}/cancellation/review',
        [BorrowingRequestController::class, 'reviewCancellation']
    )
        ->middleware('workspace:SPMU')
        ->name('requests.cancellation.review');


    /*
    |--------------------------------------------------------------------------
    | Approval Workflow - SPMU Only
    |--------------------------------------------------------------------------
    |
    | New submissions are verified/decided by SPMU. GSU/VPAF are not active
    | in-system approval stages.
    */

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/approvals', [ApprovalController::class, 'index'])
            ->name('approvals.index');

        Route::post('/approvals/{borrowingRequest}', [ApprovalController::class, 'decide'])
            ->name('approvals.decide');
    });


    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');


    /*
    |--------------------------------------------------------------------------
    | Custody - Borrower + SPMU
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/custody', [CustodyController::class, 'index'])
            ->name('custody.index');

        Route::get('/custody/{custody}', [CustodyController::class, 'show'])
            ->name('custody.show');
    });

    Route::post('/custody/{custody}/schedule-pickup', [CustodyController::class, 'schedulePickup'])
        ->middleware('workspace:SPMU')
        ->name('custody.schedule-pickup');

    Route::post('/custody/{custody}/quantities', [CustodyController::class, 'quantities'])
        ->middleware('workspace:SPMU')
        ->name('custody.quantities');

    Route::post('/custody/{custody}/prepare', [CustodyController::class, 'prepare'])
        ->middleware('workspace:SPMU')
        ->name('custody.prepare');

    Route::post('/custody/{custody}/acknowledge', [CustodyController::class, 'acknowledge'])
        ->middleware('workspace:BORROWER')
        ->name('custody.acknowledge');

    Route::post('/custody/{custody}/release', [CustodyController::class, 'release'])
        ->middleware('workspace:SPMU')
        ->name('custody.release');

    Route::post('/custody/{custody}/return', [CustodyController::class, 'receiveReturn'])
        ->middleware('workspace:SPMU')
        ->name('custody.return');

    Route::post('/custody/{custody}/early-return', [CustodyController::class, 'requestEarlyReturn'])
        ->middleware('workspace:BORROWER')
        ->name('custody.early-return');


    /*
    |--------------------------------------------------------------------------
    | Evidence
    |--------------------------------------------------------------------------
    */

    Route::post('/documents/{document}/evidence', [EvidenceController::class, 'store'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('evidence.store');

    Route::post('/evidence/{evidence}/verify', [EvidenceController::class, 'verify'])
        ->middleware('workspace:SPMU')
        ->name('evidence.verify');


    /*
    |--------------------------------------------------------------------------
    | Gate Pass
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/gate-passes/{gatePass}/verify',
        [ConditionalProcessingController::class, 'gatePass']
    )
        ->middleware('workspace:SPMU')
        ->name('gate-passes.verify');



    /*
    |--------------------------------------------------------------------------
    | Simple Laundry Worker Portal
    |--------------------------------------------------------------------------
    |
    | Laundry Worker responsibility is intentionally minimal:
    | - view the current Laundry Form / linen list
    | - upload the accomplished scanned Laundry Form
    | - mark the cleaned linen released back to the borrower
    |
    | SPMU performs the structured encoding and final asset verification.
    |
    */

    Route::middleware('workspace:LAUNDRY')->group(function (): void {
        Route::get('/laundry', [LaundryController::class, 'index'])
            ->name('laundry.index');

        Route::get('/laundry/{laundryJob}', [LaundryController::class, 'show'])
            ->name('laundry.show');

        Route::post('/laundry/{laundryJob}/upload-form', [LaundryController::class, 'upload'])
            ->name('laundry.upload-form');

        Route::post('/laundry/{laundryJob}/release-to-borrower', [LaundryController::class, 'releaseToBorrower'])
            ->name('laundry.release-to-borrower');
    });

    Route::post('/laundry/{laundryJob}/verify-form', [LaundryController::class, 'verifyForm'])
        ->middleware('workspace:SPMU')
        ->name('laundry.verify-form');


    /*
    |--------------------------------------------------------------------------
    | Laundry Processing
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/laundry/{laundry}/verify',
        [ConditionalProcessingController::class, 'laundry']
    )
        ->middleware('workspace:SPMU')
        ->name('laundry.verify');


    /*
    |--------------------------------------------------------------------------
    | Accountability
    |--------------------------------------------------------------------------
    */

    Route::get('/accountability', [AccountabilityController::class, 'index'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('accountability.index');

    Route::post('/incidents/{incident}/bill', [AccountabilityController::class, 'billIncident'])
        ->middleware('workspace:SPMU')
        ->name('incidents.bill');

    Route::post('/overdue/{overdue}/bill', [AccountabilityController::class, 'billOverdue'])
        ->middleware('workspace:SPMU')
        ->name('overdue.bill');

    Route::post('/billings/{billing}/payments', [AccountabilityController::class, 'recordPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.store');

    Route::post('/payments/{payment}/verify', [AccountabilityController::class, 'verifyPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.verify');

    Route::post('/billings/{billing}/waive', [AccountabilityController::class, 'waive'])
        ->middleware('workspace:SPMU')
        ->name('billings.waive');


    /*
    |--------------------------------------------------------------------------
    | Sanctions / Policy Configuration
    |--------------------------------------------------------------------------
    */

    Route::get('/sanctions', [SanctionController::class, 'index'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('sanctions.index');

    Route::post('/sanctions/{violation}/review', [SanctionController::class, 'review'])
        ->middleware('workspace:SPMU')
        ->name('sanctions.review');

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/administration/policies', [PolicyController::class, 'index'])
            ->name('policies.index');

        Route::post(
            '/administration/policies/academic-periods',
            [PolicyController::class, 'storeAcademicPeriod']
        )->name('policies.academic-periods.store');

        Route::put(
            '/administration/policies/academic-periods/{period}',
            [PolicyController::class, 'updateAcademicPeriod']
        )->name('policies.academic-periods.update');

        Route::post(
            '/administration/policies/sanction-rules',
            [PolicyController::class, 'storeSanctionRule']
        )->name('policies.sanction-rules.store');

        Route::put(
            '/administration/policies/sanction-rules/{rule}',
            [PolicyController::class, 'updateSanctionRule']
        )->name('policies.sanction-rules.update');
    });


    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/reports', [ReportController::class, 'index'])
            ->name('reports.index');

        Route::get('/reports/export/{type}', [ReportController::class, 'export'])
            ->name('reports.export');
    });

    Route::get('/reports/audit', [ReportController::class, 'audit'])
        ->middleware('workspace:SPMU,ICTU')
        ->name('reports.audit');

    Route::get('/reports/notifications', [ReportController::class, 'notifications'])
        ->middleware('workspace:SPMU,ICTU')
        ->name('reports.notifications');


    /*
    |--------------------------------------------------------------------------
    | Administration - SPMU + ICTU
    |--------------------------------------------------------------------------
    */

    Route::prefix('administration')
        ->name('administration.')
        ->middleware('workspace:SPMU,ICTU')
        ->group(function (): void {

            Route::get('/', [AdministrationController::class, 'index'])
                ->name('index');

            Route::get('/settings', [SettingController::class, 'index'])
                ->name('settings.index');

            Route::put('/settings/{setting}', [SettingController::class, 'update'])
                ->name('settings.update');
        });


    /*
    |--------------------------------------------------------------------------
    | ICTU Administration
    |--------------------------------------------------------------------------
    */

    Route::prefix('administration')
        ->name('administration.')
        ->middleware('workspace:ICTU')
        ->group(function (): void {

            Route::resource('users', UserAdministrationController::class)
                ->except(['show', 'destroy']);

            Route::post('/backup', [TechnicalOperationController::class, 'backup'])
                ->name('backup');

            Route::get('/delegations', [DelegationController::class, 'index'])
                ->name('delegations.index');

            Route::post('/delegations', [DelegationController::class, 'store'])
                ->name('delegations.store');

            Route::post(
                '/delegations/{delegation}/revoke',
                [DelegationController::class, 'revoke']
            )->name('delegations.revoke');
        });
});
