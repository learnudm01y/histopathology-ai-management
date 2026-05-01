@php
    $routeName = Route::currentRouteName();
    $inSettings = str_starts_with($routeName ?? '', 'admin.settings');
    $inStains = str_starts_with($routeName ?? '', 'admin.settings.stains');
@endphp
<nav class="sidebar sidebar-offcanvas dynamic-active-class-disabled" id="sidebar">
    <ul class="nav">
        <li class="nav-item nav-category">Main Menu</li>

        <li class="nav-item {{ $routeName === 'admin.dashboard' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.dashboard') }}">
                <i class="menu-icon typcn typcn-document-text"></i>
                <span class="menu-title">Dashboard</span>
            </a>
        </li>

        <li class="nav-item {{ $routeName === 'admin.samples' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.samples') }}">
                <i class="menu-icon typcn typcn-clipboard"></i>
                <span class="menu-title">Samples</span>
            </a>
        </li>

        <li class="nav-item {{ str_starts_with($routeName ?? '', 'admin.cases') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.cases.index') }}">
                <i class="menu-icon mdi mdi-account-multiple-outline"></i>
                <span class="menu-title">Cases</span>
            </a>
        </li>

        <li class="nav-item {{ $routeName === 'admin.workflow' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.workflow') }}">
                <i class="menu-icon typcn typcn-flow-merge"></i>
                <span class="menu-title">Workflow</span>
            </a>
        </li>

        <li class="nav-item {{ $routeName === 'admin.output' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.output') }}">
                <i class="menu-icon typcn typcn-document"></i>
                <span class="menu-title">Output</span>
            </a>
        </li>

        {{-- ── Settings Dropdown ──────────────────────────────────── --}}
        <li class="nav-item nav-category">System</li>

        <li class="nav-item {{ $inSettings ? 'active' : '' }}">
            <a class="nav-link sidebar-submenu-toggle" href="#settings-dropdown"
               data-target="#settings-dropdown"
               aria-expanded="{{ $inSettings ? 'true' : 'false' }}"
               aria-controls="settings-dropdown">
                <i class="menu-icon mdi mdi-settings-outline"></i>
                <span class="menu-title">Settings</span>
                <i class="menu-arrow"></i>
            </a>
            <div class="sidebar-submenu {{ $inSettings ? 'show' : '' }}" id="settings-dropdown">
                <ul class="nav flex-column sub-menu">
                    <li class="nav-item">
                        <a class="nav-link {{ str_starts_with($routeName ?? '', 'admin.settings.categories') ? 'active' : '' }}"
                           href="{{ route('admin.settings.categories.index') }}">
                            Categories
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ str_starts_with($routeName ?? '', 'admin.settings.organs') ? 'active' : '' }}"
                           href="{{ route('admin.settings.organs.index') }}">
                            Organs
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ str_starts_with($routeName ?? '', 'admin.settings.data-sources') ? 'active' : '' }}"
                           href="{{ route('admin.settings.data-sources.index') }}">
                            Data Sources
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $inStains ? 'active' : '' }}"
                           href="{{ route('admin.settings.stains.index') }}">
                            Stains
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ str_starts_with($routeName ?? '', 'admin.settings.ai-models') ? 'active' : '' }}"
                           href="{{ route('admin.settings.ai-models.index') }}">
                            AI Models
                        </a>
                    </li>
                </ul>
            </div>
        </li>

    </ul>
</nav>
@push('scripts')
<script>
(function () {
    // Custom sidebar submenu toggle: independent of Bootstrap collapse so it
    // can never get stuck open due to mismatched aria/show state.
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.sidebar-submenu-toggle');
        if (!toggle) return;
        e.preventDefault();
        var selector = toggle.getAttribute('data-target') || toggle.getAttribute('href');
        if (!selector) return;
        var panel = document.querySelector(selector);
        if (!panel) return;
        var isOpen = panel.classList.toggle('show');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

        // Close other open submenus
        document.querySelectorAll('#sidebar .sidebar-submenu.show').forEach(function (other) {
            if (other !== panel) {
                other.classList.remove('show');
                var otherToggle = document.querySelector('.sidebar-submenu-toggle[data-target="#' + other.id + '"], .sidebar-submenu-toggle[href="#' + other.id + '"]');
                if (otherToggle) otherToggle.setAttribute('aria-expanded', 'false');
            }
        });
    });
})();
</script>
<style>
    /* Mimic Bootstrap collapse behavior without the JS plugin */
    #sidebar .sidebar-submenu { display: none; }
    #sidebar .sidebar-submenu.show { display: block; }
</style>
@endpush
