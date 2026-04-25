@extends('admin.layouts.app')

@section('title', 'Output')

@section('content')
    <div class="page-header">
        <h3 class="page-title">Output</h3>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Output</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">Output</h4>
                    <p class="card-description">This page is ready for output/reports content.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
