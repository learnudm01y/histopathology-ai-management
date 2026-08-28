@extends('public.layout')

@section('title', 'MAIND PATH — computational pathology research platform')
@section('description', 'HCMA AI builds MAIND PATH, a research platform for the computational analysis of whole-slide pathology images. Not a medical device.')

@section('body')

<div class="hero">
    <div class="wrap">
        <span class="kicker">HCMA AI &middot; MAIND PATH&trade;</span>
        <h1>Computational pathology research,<br>built on responsible AI.</h1>
        <div class="lede">
            <p>
                HCMA AI is a healthcare-AI company empowering digital medicine with responsible AI
                and advanced technology. MAIND PATH is our research platform for the computational
                analysis of whole-slide pathology images.
            </p>
        </div>
        <div class="hero-actions">
            <a class="btn btn-primary" href="{{ route('public.privacy') }}">Privacy Policy</a>
            <a class="btn btn-ghost" href="{{ route('public.terms') }}">Terms of Service</a>
        </div>
    </div>
</div>

<section>
    <div class="wrap">
        <h2>What the platform does</h2>
        <div class="doc">
            <p>
                Whole-slide images are digitised pathology slides. A single slide can reach several
                gigabytes, and a research study can involve thousands of them. MAIND PATH gives our
                research team one place to manage that workload: it catalogues each slide, checks
                whether it is technically fit for analysis, splits it into image tiles, extracts
                numerical features with deep-learning models, and trains and evaluates classification
                models on the result.
            </p>
            <p>
                The platform is operated by HCMA AI for its own research and development. It is used
                by our internal research team, and it is not a consumer product and not open to public
                sign-up.
            </p>
        </div>

        <div class="grid">
            <div class="card">
                <div class="mark"></div>
                <h3>Slide catalogue and quality control</h3>
                <p>
                    Every slide is registered with its source, organ and disease group, then checked
                    against a documented eligibility standard before it can enter a study.
                </p>
            </div>
            <div class="card">
                <div class="mark"></div>
                <h3>Tiling and feature extraction</h3>
                <p>
                    Slides are divided into tissue tiles and passed through foundation models to
                    produce compact numerical representations for downstream analysis.
                </p>
            </div>
            <div class="card">
                <div class="mark"></div>
                <h3>Model training and evaluation</h3>
                <p>
                    Multiple-instance-learning models are trained on those representations and
                    reported with the metrics needed to judge whether a result is trustworthy.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="tint">
    <div class="wrap">
        <h2>The data we work with</h2>
        <div class="doc">
            <p>
                Our current studies are built on publicly available, de-identified research datasets,
                including <strong>TCGA</strong> (The Cancer Genome Atlas), <strong>GTEx</strong>
                (Genotype-Tissue Expression) and <strong>BRACS</strong> (BReAst Carcinoma Subtyping).
                These datasets are released for research use and carry no direct patient identifiers.
            </p>
            <p>
                Where we work with material contributed by a partner institution, that material is
                de-identified by the contributing institution before it reaches us, and it is handled
                under the written agreement with that institution.
            </p>
        </div>
    </div>
</section>

<section>
    <div class="wrap">
        <h2>How we use Google Drive</h2>
        <div class="doc">
            <p>
                MAIND PATH uses Google Drive as the storage backend for its own research files.
                The platform is connected to a single Google account that belongs to HCMA AI, and it
                reads and writes only inside the folders that account owns.
            </p>
            <div class="callout">
                <p>
                    <strong>The platform never asks you to connect your Google account.</strong>
                    There is no "Sign in with Google" anywhere in MAIND PATH, and the platform does
                    not read, request or receive Google account data belonging to visitors, partners
                    or any third party.
                </p>
            </div>
            <p>
                Our <a href="{{ route('public.privacy') }}">Privacy Policy</a> explains in full which
                Google API scope the platform requests, why it is needed, what is stored, how long it
                is kept, and how access can be withdrawn.
            </p>
        </div>
    </div>
</section>

<section class="tint">
    <div class="wrap">
        <h2>Research use only</h2>
        <div class="doc">
            <p>
                MAIND PATH is a research and development platform. It is <strong>not</strong> a medical
                device, it has not been reviewed or cleared by any medical-device regulator, and its
                output must not be used to diagnose, treat, or make any clinical decision about a
                patient. Every result it produces requires interpretation and confirmation by a
                qualified pathologist working from the original material.
            </p>
            <p>
                Questions about this platform, our data handling, or these pages can be sent to
                <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
            </p>
        </div>
    </div>
</section>

@endsection
