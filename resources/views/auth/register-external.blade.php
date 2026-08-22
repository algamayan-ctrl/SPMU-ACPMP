@extends('layouts.app', ['title' => 'External Borrower Registration'])


@section('content')

<section
    class="external-register-page external-register-shell"
    style="
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 24px 16px !important;
        display: flex !important;
        justify-content: center !important;
        align-items: flex-start !important;
        box-sizing: border-box !important;
    "
>

    <article
    class="auth-card external-register-card"
    style="
        width: 530px !important;
        max-width: calc(100vw - 32px) !important;
        min-width: 0 !important;
        margin: 0 auto !important;
        padding: 28px 34px 30px !important;
        box-sizing: border-box !important;
        text-align: left !important;
    "
>

        <div class="auth-heading external-register-heading">
            <span class="external-register-eyebrow">
                EXTERNAL BORROWER ACCESS
            </span>

            <h1>
                Create an External Borrower Account
            </h1>

            <p>
                Register to prepare borrowing requests for an external organization.
            </p>
        </div>


        @if($errors->any())
            <div class="external-register-errors" role="alert">
                <strong>Please correct the following:</strong>

                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif


        <form
            method="post"
            action="{{ route('external.register.store') }}"
            class="external-register-form"
        >
            @csrf


            <div class="external-register-grid">

                <div class="auth-field-group">
                    <label for="external-full-name">
                        Full Name
                    </label>

                    <input
                        id="external-full-name"
                        type="text"
                        name="full_name"
                        value="{{ old('full_name') }}"
                        required
                        autocomplete="name"
                    >
                </div>


                <div class="auth-field-group">
                    <label for="external-email">
                        Email Address
                    </label>

                    <input
                        id="external-email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="email"
                    >
                </div>


                <div class="auth-field-group">
                    <label for="external-mobile">
                        Mobile Number
                    </label>

                    <input
                        id="external-mobile"
                        type="text"
                        name="mobile_no"
                        value="{{ old('mobile_no') }}"
                        required
                        autocomplete="tel"
                    >
                </div>


                <div class="auth-field-group">
                    <label for="external-organization">
                        Organization / Agency / Company
                    </label>

                    <input
                        id="external-organization"
                        type="text"
                        name="organization_name"
                        value="{{ old('organization_name') }}"
                        required
                        autocomplete="organization"
                    >
                </div>

            </div>


            <div class="auth-field-group">
                <label for="external-address">
                    Organization Address
                </label>

                <textarea
                    id="external-address"
                    name="organization_address"
                    required
                    rows="2"
                >{{ old('organization_address') }}</textarea>
            </div>


            <div class="external-register-grid">

                <div class="auth-field-group">
                    <label for="external-password">
                        Password
                    </label>

                    <input
                        id="external-password"
                        type="password"
                        name="password"
                        required
                        autocomplete="new-password"
                    >

                    <small>
                        At least 12 characters with letters, numbers, and symbols.
                    </small>
                </div>


                <div class="auth-field-group">
                    <label for="external-password-confirmation">
                        Confirm Password
                    </label>

                    <input
                        id="external-password-confirmation"
                        type="password"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                    >
                </div>

            </div>


            <button
                type="submit"
                class="button primary ui-pressable external-register-submit"
            >
                Create Account
            </button>

        </form>


        <div class="external-register-footer">
            <span>Already have an account?</span>

            <a href="{{ route('login') }}">
                Sign in
            </a>
        </div>

    </article>

</section>









<style id="exact-external-registration-size">

.external-register-heading {
    margin-bottom: 18px !important;
    text-align: left !important;
}

.external-register-eyebrow {
    display: block;
    margin-bottom: 5px;

    color: #49637f;

    font-size: 9px;
    line-height: 1.2;
    font-weight: 800;

    letter-spacing: .08em;
}

.external-register-heading h1 {
    margin: 0 0 5px !important;

    font-size: 24px !important;
    line-height: 1.22 !important;
    font-weight: 700 !important;
}

.external-register-heading p {
    margin: 0 !important;

    color: #647489;

    font-size: 11.5px !important;
    line-height: 1.45 !important;
}


.external-register-form {
    display: grid !important;
    gap: 11px !important;

    margin: 0 !important;

    text-align: left !important;
}


.external-register-grid {
    display: grid !important;

    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr) !important;

    gap: 10px 12px !important;
}


.external-register-card .auth-field-group {
    display: flex !important;
    flex-direction: column !important;

    gap: 5px !important;

    min-width: 0 !important;
}


.external-register-card .auth-field-group > label {
    margin: 0 !important;

    color: #142235;

    font-size: 11px !important;
    line-height: 1.3 !important;
    font-weight: 700 !important;
}


.external-register-card input,
.external-register-card textarea {
    width: 100% !important;

    box-sizing: border-box !important;

    margin: 0 !important;

    border: 1px solid #b9c9db !important;
    border-radius: 8px !important;

    background: #fff !important;

    color: #142235 !important;

    font-size: 12px !important;

    outline: none !important;
}


.external-register-card input {
    height: 38px !important;
    min-height: 38px !important;

    padding: 7px 10px !important;
}


.external-register-card textarea {
    height: 54px !important;
    min-height: 54px !important;

    padding: 7px 10px !important;

    resize: vertical;
}


.external-register-card input:focus,
.external-register-card textarea:focus {
    border-color: #1769aa !important;

    box-shadow:
        0 0 0 3px rgba(23, 105, 170, .08) !important;
}


.external-register-card small {
    color: #647489 !important;

    font-size: 9px !important;
    line-height: 1.3 !important;
}


.external-register-submit {
    width: 100% !important;

    min-height: 40px !important;

    margin-top: 2px !important;

    padding: 8px 14px !important;

    font-size: 12px !important;
}


.external-register-footer {
    display: flex !important;

    align-items: center !important;
    justify-content: center !important;

    gap: 5px !important;

    margin-top: 13px !important;
    padding-top: 12px !important;

    border-top: 1px solid #dce5f0 !important;

    color: #647489 !important;

    font-size: 11px !important;

    text-align: center !important;
}


.external-register-errors {
    margin-bottom: 12px !important;

    padding: 9px 11px !important;

    font-size: 10.5px !important;
}


@media (max-width: 560px) {

    .external-register-grid {
        grid-template-columns: 1fr !important;
    }

    .auth-card.external-register-card {
        width: calc(100vw - 24px) !important;

        padding: 22px 20px 24px !important;
    }

    .external-register-heading h1 {
        font-size: 22px !important;
    }
}

</style>



<style id="external-register-header-fix">

.external-register-page {
    width: 100% !important;
    min-height: calc(100vh - 74px) !important;

    display: flex !important;
    justify-content: center !important;
    align-items: flex-start !important;

    padding: 28px 16px 40px !important;

    background: #ecf1f7 !important;

    box-sizing: border-box !important;
}

.external-register-page .external-register-card {
    width: 530px !important;
    max-width: calc(100vw - 32px) !important;
    margin: 0 auto !important;
}

@media (max-width: 560px) {

    .external-register-page {
        padding: 18px 12px 30px !important;
    }

    .external-register-page .external-register-card {
        width: calc(100vw - 24px) !important;
        max-width: none !important;
    }
}

</style>
@endsection