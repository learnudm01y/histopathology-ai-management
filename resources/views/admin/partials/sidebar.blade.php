@php
    $routeName = Route::currentRouteName();
    $inSettings = str_starts_with($routeName ?? '', 'admin.settings');
@endphp
<nav class="sidebar sidebar-offcanvas" id="sidebar">
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
            <a class="nav-link" data-toggle="collapse" href="#settings-dropdown"
               aria-expanded="{{ $inSettings ? 'true' : 'false' }}"
               aria-controls="settings-dropdown">
                <i class="menu-icon mdi mdi-settings-outline"></i>
                <span class="menu-title">Settings</span>
                <i class="menu-arrow"></i>
            </a>
            <div class="collapse {{ $inSettings ? 'show' : '' }}" id="settings-dropdown">
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
                </ul>
            </div>
        </li>

    </ul>
</nav>
