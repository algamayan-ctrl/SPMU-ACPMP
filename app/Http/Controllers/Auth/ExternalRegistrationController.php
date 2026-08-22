<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\UserRoleAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ExternalRegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register-external');
    }

    public function store(
        Request $request,
        UserRoleAssignmentService $roleAssignments,
    ): RedirectResponse {
        $data = $request->validate([
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'mobile_no' => [
                'required',
                'string',
                'max:30',
            ],

            'organization_name' => [
                'required',
                'string',
                'max:255',
            ],

            'organization_address' => [
                'required',
                'string',
                'max:1000',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::min(12)
                    ->letters()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        DB::transaction(function () use (
            $data,
            $roleAssignments
        ): void {
            /*
             * External borrowers remain separate from CSPC
             * organizational units.
             */
            $externalUnit = OrganizationalUnit::query()
                ->where('unit_code', 'EXTERNAL')
                ->first();

            if (! $externalUnit) {
                $externalUnit = new OrganizationalUnit();

                $externalUnit->forceFill([
                    'unit_code' => 'EXTERNAL',
                    'unit_name' => 'External Borrowers',
                    'active' => true,
                ]);

                $externalUnit->save();
            }

            /*
             * employee_no remains required by the existing users
             * schema, so external accounts receive a system-generated
             * Borrower Number instead of an employee number.
             */
            do {
                $borrowerNumber =
                    'EXT-'
                    .now()->format('YmdHis')
                    .'-'
                    .Str::upper(Str::random(6));
            } while (
                User::query()
                    ->where('employee_no', $borrowerNumber)
                    ->exists()
            );

            /*
             * employment_type is an existing institutional field.
             * External identity is determined by borrower_type,
             * not by employment_type.
             */
            $employmentType =
                User::query()
                    ->where(
                        'access_classification',
                        AccessClassification::BorrowerOnly->value
                    )
                    ->whereNotNull('employment_type')
                    ->value('employment_type');

            if (! $employmentType) {
                $employmentCases = EmploymentType::cases();

                if (count($employmentCases) === 0) {
                    throw new \RuntimeException(
                        'No employment type is configured.'
                    );
                }

                $employmentType =
                    $employmentCases[0]->value;
            }

            $classification =
                AccessClassification::BorrowerOnly;

            $user = new User();

            $user->forceFill([
                'organizational_unit_id' =>
                    $externalUnit->id,

                'employee_no' =>
                    $borrowerNumber,

                'full_name' =>
                    trim($data['full_name']),

                'designation' =>
                    'External Borrower',

                'employment_type' =>
                    $employmentType,

                'email' =>
                    strtolower(trim($data['email'])),

                'mobile_no' =>
                    trim($data['mobile_no']),

                'notification_preferences' => [
                    'system' => true,
                    'email' => true,
                    'sms' => true,
                ],

                /*
                 * Account is ACTIVE so the borrower can sign in.
                 *
                 * Borrowing verification remains PENDING and is
                 * checked independently before request submission.
                 */
                'account_status' =>
                    AccountStatus::Active->value,

                'access_classification' =>
                    $classification->value,

                'borrower_type' =>
                    'EXTERNAL',

                'borrower_verification_status' =>
                    'PENDING',

                'organization_name' =>
                    trim($data['organization_name']),

                'organization_address' =>
                    trim($data['organization_address']),

                'borrower_verified_at' =>
                    null,

                'borrower_verified_by_user_id' =>
                    null,

                /*
                 * Email verification is not yet enforced in Phase 1.
                 */
                'email_verified_at' =>
                    null,

                'password' =>
                    Hash::make($data['password']),
            ]);

            $user->save();

            /*
             * External registrants can never select their own role.
             * They always receive BORROWER_ONLY.
             */
            $roleAssignments->synchronize(
                $user,
                $classification
            );
        });

        return redirect()
            ->route('login')
            ->with(
                'status',
                'External borrower account created successfully. You may sign in and prepare draft requests. SPMU verification is required before request submission.'
            );
    }
}