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
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
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

    Route::post('/profile/signature', [ProfileController::class, 'signature'])
        ->name('profile.signature');

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
    | Kept separate from Inventory permissions.
    |
    */

    Route::middleware('workspace:BORROWER,SPMU,GSU,VPAF')->group(function (): void {

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
    | Borrowing Requests - View
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER,SPMU,GSU,VPAF')->group(function (): void {

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


    /*
    |--------------------------------------------------------------------------
    | Approval Workflow
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU,GSU,VPAF')->group(function (): void {

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
        '/gate-passes/{gatePass}/sign-verified',
        [ConditionalProcessingController::class, 'signGatePassVerified']
    )
        ->middleware('workspace:SPMU')
        ->name('gate-passes.sign-verified');

    Route::post(
        '/gate-passes/{gatePass}/sign-approved',
        [ConditionalProcessingController::class, 'signGatePassApproved']
    )
        ->middleware('workspace:SPMU')
        ->name('gate-passes.sign-approved');

    Route::post(
        '/gate-passes/{gatePass}/verify',
        [ConditionalProcessingController::class, 'gatePass']
    )
        ->middleware('workspace:SPMU')
        ->name('gate-passes.verify');


    /*
    |--------------------------------------------------------------------------
    | Laundry Processing
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/custody/{custody}/laundry-approve',
        [ConditionalProcessingController::class, 'approveLaundryForm']
    )
        ->middleware('workspace:SPMU')
        ->name('laundry.approve-form');

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
        ->middleware('workspace:BORROWER')
        ->name('payments.store');

    Route::post('/payments/{payment}/verify', [AccountabilityController::class, 'verifyPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.verify');

    Route::post('/billings/{billing}/waive', [AccountabilityController::class, 'waive'])
        ->middleware('workspace:SPMU')
        ->name('billings.waive');


    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU,VPAF')->group(function (): void {

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