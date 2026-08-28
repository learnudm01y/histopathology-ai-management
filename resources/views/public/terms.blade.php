@extends('public.layout')

@section('title', 'Terms of Service')
@section('description', 'The terms that govern use of the MAIND PATH research platform operated by HCMA AI.')

@section('body')

<div class="page-head">
    <div class="wrap">
        <h1>Terms of Service</h1>
        <p class="meta">
            MAIND PATH&trade; research platform, operated by HCMA AI &middot;
            Effective {{ $effectiveDate }} &middot; Last updated {{ $effectiveDate }}
        </p>
    </div>
</div>

<section>
    <div class="wrap doc">

        <p>
            These Terms of Service ("Terms") govern access to and use of the website at
            <code>{{ $domain }}</code> and the MAIND PATH research platform (together, the "Service"),
            operated by HCMA AI ("HCMA AI", "we", "us"). By accessing the Service you agree to these
            Terms. If you do not agree, please do not use the Service.
        </p>

        <div class="callout">
            <p>
                <strong>Research use only.</strong> MAIND PATH is a research and development platform.
                It is not a medical device, it has not been reviewed or cleared by any medical-device
                regulator, and nothing it produces may be used to diagnose a condition, to guide
                treatment, or to make any other clinical decision about a patient.
            </p>
        </div>

        <h2>1. The Service</h2>
        <p>
            MAIND PATH supports research into computational pathology. It catalogues whole-slide
            pathology images, assesses whether they are technically suitable for analysis, divides them
            into image tiles, extracts numerical features using machine-learning models, and trains and
            evaluates classification models on those features.
        </p>
        <p>
            The public pages of this website are open to everyone. The platform itself is private:
            accounts are created by HCMA AI for its own research team and for collaborators working under
            a written agreement. There is no public registration.
        </p>

        <h2>2. Eligibility and accounts</h2>
        <ul>
            <li>You must be at least 18 years old to use the Service.</li>
            <li>
                If HCMA AI issues you an account, it is personal to you. You are responsible for keeping
                your credentials confidential and for activity carried out under your account.
            </li>
            <li>
                Tell us promptly at <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a> if you
                believe your account has been used without your authorisation.
            </li>
        </ul>

        <h2>3. Research use, and no medical advice</h2>
        <p>
            Outputs of the Service — including quality assessments, feature representations, model
            predictions and probability scores — are research artefacts. They are produced by statistical
            models that can be wrong, and their accuracy depends on data the model was never guaranteed to
            have seen. They are not a diagnosis and not a second opinion.
        </p>
        <p>
            Any interpretation of pathology material must be made by a qualified pathologist working from
            the original material and the full clinical context. You agree not to represent an output of
            the Service as a clinical finding, and not to deploy the Service in a care pathway without
            the regulatory approvals that would be required for such use.
        </p>

        <h2>4. Acceptable use</h2>
        <p>When using the Service, you agree not to:</p>
        <ul>
            <li>upload material you do not have the right to use, or that has not been properly de-identified;</li>
            <li>attempt to re-identify any individual from any dataset in the Service;</li>
            <li>
                attempt to gain unauthorised access to the Service, to other accounts, or to the
                infrastructure that runs it, or to interfere with its normal operation;
            </li>
            <li>copy, resell or redistribute the Service or its outputs outside the terms of your agreement with us;</li>
            <li>use the Service in breach of any applicable law, or of the licence terms of any dataset it holds.</li>
        </ul>

        <h2>5. Data and content</h2>
        <ul>
            <li>
                <strong>Your material.</strong> Material you contribute to the Service under an agreement
                with us remains yours. You grant us the limited right to store and process it in order to
                provide the Service and to carry out the research described in that agreement.
            </li>
            <li>
                <strong>Public datasets.</strong> Datasets such as TCGA, GTEx and BRACS remain subject to
                the licences and data-use agreements published by the bodies that release them. Those
                terms apply to you as well as to us.
            </li>
            <li>
                <strong>Our material.</strong> The Service itself — its software, interface, models,
                documentation, and the HCMA AI and MAIND PATH names and logos — belongs to HCMA AI. These
                Terms do not transfer any of those rights to you.
            </li>
        </ul>
        <p>
            How we handle personal information is described in our
            <a href="{{ route('public.privacy') }}">Privacy Policy</a>, which forms part of these Terms.
        </p>

        <h2>6. Third-party services</h2>
        <p>
            The Service relies on third-party infrastructure, including Google Drive for file storage and
            cloud providers for hosting and GPU compute. Use of those services is governed by their own
            terms, and their availability is outside our control. Our use of data obtained through Google
            APIs is described in section 3 of the
            <a href="{{ route('public.privacy') }}">Privacy Policy</a>.
        </p>

        <h2>7. Availability</h2>
        <p>
            We aim to keep the Service running and available, but we do not promise uninterrupted
            availability. We may suspend it for maintenance, change or withdraw features, or discontinue
            it entirely. Where we can reasonably give advance notice of a change that materially affects
            you, we will.
        </p>

        <h2>8. Disclaimer of warranties</h2>
        <p>
            To the fullest extent permitted by applicable law, the Service is provided "as is" and "as
            available", without warranties of any kind, whether express or implied, including any implied
            warranty of merchantability, fitness for a particular purpose, or non-infringement. We do not
            warrant that the Service will be error-free, that results will be accurate or complete, or
            that any defect will be corrected.
        </p>

        <h2>9. Limitation of liability</h2>
        <p>
            To the fullest extent permitted by applicable law, HCMA AI will not be liable for any
            indirect, incidental, special, consequential or exemplary damages, or for any loss of profits,
            revenue, data or goodwill, arising out of or in connection with the Service.
        </p>
        <p>
            Nothing in these Terms excludes or limits liability that cannot lawfully be excluded or
            limited, including liability for death or personal injury caused by negligence, or for fraud.
        </p>

        <h2>10. Indemnity</h2>
        <p>
            You agree to indemnify HCMA AI against claims, losses and reasonable costs arising from your
            breach of these Terms, your misuse of the Service, or your use of an output of the Service in
            a clinical setting contrary to section 3.
        </p>

        <h2>11. Suspension and termination</h2>
        <p>
            We may suspend or terminate access to the Service where these Terms are breached, where
            continued access would create a legal or security risk, or where the underlying agreement with
            you ends. You may stop using the Service at any time. Sections 5, 8, 9, 10 and 13 survive
            termination.
        </p>

        <h2>12. Changes to these Terms</h2>
        <p>
            We may update these Terms. The revised version will be published on this page with a new
            effective date, and it applies from the date it is published. If you continue to use the
            Service after that date, you accept the revised Terms.
        </p>

        <h2>13. Governing law</h2>
        <p>
            These Terms are governed by the laws of the Kingdom of Saudi Arabia. The courts of the Kingdom
            of Saudi Arabia have jurisdiction over any dispute arising out of or in connection with them,
            without prejudice to any mandatory protection available to you under the law of your place of
            residence.
        </p>

        <h2>14. Contact</h2>
        <p>
            Questions about these Terms:
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
        </p>

    </div>
</section>

@endsection
