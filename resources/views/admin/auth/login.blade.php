<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login | Histopathology Management</title>

    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/iconfonts/mdi/css/materialdesignicons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/iconfonts/ionicons/dist/css/ionicons.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/iconfonts/flag-icon-css/css/flag-icon.min.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/css/vendor.bundle.base.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/css/vendor.bundle.addons.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/css/shared/style.css') }}">
    <link rel="shortcut icon" href="{{ asset('admin-assets/images/favicon.ico') }}" />
</head>
<body>
<div class="container-scroller">
    <div class="container-fluid page-body-wrapper full-page-wrapper">
        <div class="content-wrapper d-flex align-items-center auth auth-bg-1 theme-one">
            <div class="row w-100">
                <div class="col-lg-4 mx-auto">
                    <div class="auto-form-wrapper">
                        <form method="POST" action="{{ route('admin.login.submit') }}">
                            @csrf

                            @if ($errors->any())
                                <div class="alert alert-danger">
                                    <ul class="mb-0" style="padding-left:1rem;">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="form-group">
                                <label class="label">Email</label>
                                <div class="input-group">
                                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                                           class="form-control" placeholder="Email">
                                    <div class="input-group-append">
                                        <span class="input-group-text">
                                            <i class="mdi mdi-check-circle-outline"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="label">Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" required
                                           class="form-control" placeholder="*********">
                                    <div class="input-group-append">
                                        <span class="input-group-text">
                                            <i class="mdi mdi-check-circle-outline"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary submit-btn btn-block">Login</button>
                            </div>
                            <div class="form-group d-flex justify-content-between">
                                <div class="form-check form-check-flat mt-0">
                                    <label class="form-check-label">
                                        <input type="checkbox" name="remember" class="form-check-input"> Keep me signed in
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <p class="footer-text text-center">Copyright © {{ date('Y') }} Histopathology Management. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('admin-assets/vendors/js/vendor.bundle.base.js') }}"></script>
<script src="{{ asset('admin-assets/vendors/js/vendor.bundle.addons.js') }}"></script>
<script src="{{ asset('admin-assets/js/shared/off-canvas.js') }}"></script>
<script src="{{ asset('admin-assets/js/shared/misc.js') }}"></script>
</body>
</html>
