<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | Histopathology Management</title>

    {{-- plugins:css --}}
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/iconfonts/mdi/css/materialdesignicons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/iconfonts/ionicons/dist/css/ionicons.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/iconfonts/flag-icon-css/css/flag-icon.min.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/css/vendor.bundle.base.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/vendors/css/vendor.bundle.addons.css') }}">

    {{-- page-level css --}}
    @stack('styles')

    <link rel="stylesheet" href="{{ asset('admin-assets/css/shared/style.css') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/css/demo_1/style.css') }}">
    <link rel="shortcut icon" href="{{ asset('admin-assets/images/favicon.ico') }}" />
</head>
<body>
    <div class="container-scroller">
        @include('admin.partials.navbar')

        <div class="container-fluid page-body-wrapper">
            @include('admin.partials.sidebar')

            <div class="main-panel">
                <div class="content-wrapper">
                    @yield('content')
                </div>
                @include('admin.partials.footer')
            </div>
        </div>
    </div>

    {{-- plugins:js --}}
    <script src="{{ asset('admin-assets/vendors/js/vendor.bundle.base.js') }}"></script>
    <script src="{{ asset('admin-assets/vendors/js/vendor.bundle.addons.js') }}"></script>

    {{-- shared js --}}
    <script src="{{ asset('admin-assets/js/shared/off-canvas.js') }}"></script>
    <script src="{{ asset('admin-assets/js/shared/misc.js') }}"></script>

    {{-- page-level modals (must come before scripts so modal elements exist in DOM) --}}
    @stack('modals')

    {{-- page-level js --}}
    @stack('scripts')
</body>
</html>
