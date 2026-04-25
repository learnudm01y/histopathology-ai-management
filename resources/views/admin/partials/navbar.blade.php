<nav class="navbar default-layout col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
    <div class="text-center navbar-brand-wrapper d-flex align-items-top justify-content-center">
        <a class="navbar-brand brand-logo" href="{{ route('admin.dashboard') }}" style="font-size:1.1rem;font-weight:700;color:#fff;letter-spacing:1px;text-decoration:none;">
            HISTOPAHTOLOGY-AI
        </a>
        <a class="navbar-brand brand-logo-mini" href="{{ route('admin.dashboard') }}" style="font-size:.75rem;font-weight:700;color:#fff;text-decoration:none;">
            H-AI
        </a>
    </div>
    <div class="navbar-menu-wrapper d-flex align-items-center">
        <ul class="navbar-nav">
            <li class="nav-item font-weight-semibold d-none d-lg-block">Histopathology Management</li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown d-none d-xl-inline-block user-dropdown">
                <a class="nav-link dropdown-toggle" id="UserDropdown" href="#" data-toggle="dropdown" aria-expanded="false">
                    <img class="img-xs rounded-circle" src="{{ asset('admin-assets/images/faces/face8.jpg') }}" alt="Profile image">
                </a>
                <div class="dropdown-menu dropdown-menu-right navbar-dropdown" aria-labelledby="UserDropdown">
                    <div class="dropdown-header text-center">
                        <img class="img-md rounded-circle" src="{{ asset('admin-assets/images/faces/face8.jpg') }}" alt="Profile image">
                        <p class="mb-1 mt-3 font-weight-semibold">{{ auth()->user()->name ?? 'Admin' }}</p>
                        <p class="font-weight-light text-muted mb-0">{{ auth()->user()->email ?? '' }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item" style="width:100%;text-align:left;border:0;background:none;">
                            Sign Out <i class="dropdown-item-icon ti-power-off"></i>
                        </button>
                    </form>
                </div>
            </li>
        </ul>
        <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas">
            <span class="mdi mdi-menu"></span>
        </button>
    </div>
</nav>
