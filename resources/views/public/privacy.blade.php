@extends('public.layout')

@section('title', 'Privacy Policy')
@section('description', 'How HCMA AI handles information in the MAIND PATH research platform, including data accessed through Google APIs.')

@section('body')

<div class="page-head">
    <div class="wrap">
        <h1>Privacy Policy</h1>
        <p class="meta">
            MAIND PATH&trade; research platform, operated by HCMA AI &middot;
            Effective {{ $effectiveDate }} &middot; Last updated {{ $effectiveDate }}
        </p>
    </div>
</div>

<section>
    <div class="wrap doc">

        <p>
            This Privacy Policy explains what information HCMA AI ("HCMA AI", "we", "us") handles when
            you visit <code>{{ $domain }}</code> or use the MAIND PATH research platform, and how we
            handle data accessed through Google APIs. We have written it to describe what the platform
            actually does, rather than to reserve rights we do not exercise.
        </p>

        <div class="callout">
            <p>
                <strong>Summary.</strong> MAIND PATH is an internal research platform. It does not offer
                public sign-up, it has no "Sign in with Google" feature, and it never requests access to
                the Google account of a visitor, partner or any other third party. It connects to a
                single Google Drive account owned by HCMA AI and uses it to store HCMA AI's own research
                files. We do not sell personal information and we do not use any data for advertising.
            </p>
        </div>

        <h2>1. Who we are and how to reach us</h2>
        <p>
            HCMA AI is a healthcare-artificial-intelligence company established in the Kingdom of Saudi
            Arabia. HCMA AI is the controller of the information described in this policy.
        </p>
        <ul>
            <li>Platform: MAIND PATH, at <code>{{ $domain }}</code></li>
            <li>Privacy contact: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></li>
        </ul>
        <p>
            We aim to answer any privacy request sent to that address within 30 days.
        </p>

        <h2>2. Information we handle</h2>

        <h3>2.1 Visitors to this website</h3>
        <p>
            The public pages of this site (this page, the home page and the Terms of Service) do not
            require an account, do not use advertising or analytics trackers, and do not set cookies for
            tracking. Our web server keeps standard technical logs — IP address, date and time, the page
            requested, HTTP status and browser user-agent — which we use only to operate the service and
            to investigate faults and abuse. These logs are retained for up to 90 days and then deleted.
        </p>

        <h3>2.2 Platform users</h3>
        <p>
            Access to MAIND PATH is limited to accounts created by HCMA AI for our own research team.
            For each such account we store a name, an email address and a hashed password, together with
            session records and an audit trail of actions taken in the platform. Accounts are not
            available through public registration.
        </p>

        <h3>2.3 Research material</h3>
        <p>
            The platform processes whole-slide pathology images and their associated study metadata.
            Our current datasets are publicly available, de-identified research datasets — including
            TCGA, GTEx and BRACS — which are distributed for research use and contain no direct patient
            identifiers. Material contributed by a partner institution is de-identified by that
            institution before it reaches us and is handled under the written agreement with them.
            We do not seek, and do not knowingly hold, direct patient identifiers such as names,
            national identity numbers, contact details, or full dates of birth.
        </p>

        <h2>3. Data accessed through Google APIs</h2>
        <p>
            MAIND PATH uses Google Drive as the storage backend for the large image files and derived
            artefacts it produces. This section describes that use in full.
        </p>

        <h3>3.1 Whose Google account is used</h3>
        <p>
            The platform is authorised against <strong>one Google account, owned and controlled by
            HCMA AI</strong>. That authorisation is performed once by an HCMA AI administrator. The
            platform does not offer Google sign-in to anyone else, and it cannot access the Google
            account of a visitor, a research partner, or any other person.
        </p>

        <h3>3.2 Scope requested and why</h3>
        <table class="terms">
            <tr>
                <th style="width:38%">Scope</th>
                <th>Why the platform needs it</th>
            </tr>
            <tr>
                <td><code>https://www.googleapis.com/auth/drive</code></td>
                <td>
                    The platform creates and maintains its own folder hierarchy in the HCMA AI Drive
                    account; uploads whole-slide images, image tiles, extracted feature files and trained
                    model checkpoints; reads those files back for processing; and deletes superseded
                    artefacts so storage does not grow without limit. It also has to read slide files
                    that an administrator has placed in the account manually rather than through the
                    platform, which a create-only scope would not permit.
                </td>
            </tr>
        </table>
        <p>
            We request no other Google API scope. In particular, the platform does not access Gmail,
            Google Contacts, Google Calendar, Google Photos, or any Google account profile data beyond
            what is strictly required to complete the Drive authorisation.
        </p>

        <h3>3.3 What is stored in Google Drive</h3>
        <ul>
            <li>Whole-slide pathology image files, in formats such as <code>.svs</code>.</li>
            <li>Image tiles derived from those slides.</li>
            <li>Numerical feature files produced by our models.</li>
            <li>Trained model checkpoints and the logs of the training runs that produced them.</li>
        </ul>
        <p>
            These are HCMA AI's own research assets. No personal information about website visitors or
            platform users is written to Google Drive.
        </p>

        <h3>3.4 Limited Use commitment</h3>
        <div class="callout">
            <p>
                MAIND PATH's use and transfer of information received from Google APIs to any other app
                will adhere to the
                <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener" target="_blank">Google
                API Services User Data Policy</a>, including the Limited Use requirements.
            </p>
        </div>
        <p>Concretely, this means that data obtained through Google APIs is:</p>
        <ul>
            <li>used only to provide and improve the functions of MAIND PATH described in this policy;</li>
            <li>never sold, rented, or licensed to anyone;</li>
            <li>never used for advertising, ad targeting, or profile building of any kind;</li>
            <li>never transferred to a data broker or an information reseller;</li>
            <li>
                not read by humans, except by the HCMA AI administrators who operate the platform, where
                you have given explicit consent for specific files, where it is necessary for security
                purposes such as investigating abuse, or where the law requires it.
            </li>
        </ul>

        <h3>3.5 Withdrawing access and deleting data</h3>
        <p>
            The Google account owner can revoke the platform's access at any time from the Google account
            security settings, at
            <a href="https://myaccount.google.com/permissions" rel="noopener" target="_blank">myaccount.google.com/permissions</a>.
            Revoking access stops all further reading and writing by the platform immediately; files
            already in the Drive account remain under the account owner's control and can be deleted
            there. A request to delete data we hold can also be sent to
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
        </p>

        <h2>4. How we protect information</h2>
        <ul>
            <li>All traffic to <code>{{ $domain }}</code> is served over HTTPS.</li>
            <li>The platform is behind authentication; there is no anonymous access to research data.</li>
            <li>Passwords are stored only as salted hashes, never in readable form.</li>
            <li>
                Google API credentials are stored in a file readable only by the service account that runs
                the platform, and are never exposed to the browser or included in any page.
            </li>
            <li>Access to the production server is restricted to named administrators using SSH keys.</li>
        </ul>
        <p>
            No system can be guaranteed perfectly secure. We work to protect information using measures
            appropriate to its sensitivity, but we cannot promise absolute security.
        </p>

        <h2>5. Sharing and sub-processors</h2>
        <p>
            We do not sell personal information and we do not share it for advertising. We rely on a small
            number of infrastructure providers to run the platform:
        </p>
        <ul>
            <li><strong>Google Drive</strong> — storage of the research files described in section 3.</li>
            <li><strong>Our hosting provider</strong> — the server that runs the web application and database.</li>
            <li>
                <strong>Cloud GPU compute providers</strong> — on-demand machines that run tiling, feature
                extraction and model training. Research files are transferred to these machines for the
                duration of a job and removed when the job ends.
            </li>
        </ul>
        <p>
            These providers act on our instructions. We may also disclose information where we are legally
            required to do so, or to establish or defend a legal claim.
        </p>

        <h2>6. International transfers</h2>
        <p>
            The providers listed above operate data centres in several countries, so information may be
            processed outside the Kingdom of Saudi Arabia. Where that happens we rely on the contractual
            protections offered by those providers.
        </p>

        <h2>7. Retention</h2>
        <ul>
            <li>Web-server logs: up to 90 days.</li>
            <li>Platform accounts and audit records: for as long as the account is active, and up to 12 months afterwards.</li>
            <li>
                Research files in Google Drive: for as long as they are needed for the study they belong
                to, and until the account owner deletes them.
            </li>
        </ul>

        <h2>8. Your rights</h2>
        <p>
            Subject to applicable law, including the Saudi Personal Data Protection Law, you may ask us to
            confirm what personal data we hold about you, to provide a copy of it, to correct it, to delete
            it, or to restrict how we use it. Send any such request to
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. We do not use your data for
            automated decision-making that produces legal effects concerning you.
        </p>

        <h2>9. Children</h2>
        <p>
            MAIND PATH is a professional research tool. It is not directed at children, and we do not
            knowingly create accounts for anyone under 18.
        </p>

        <h2>10. Changes to this policy</h2>
        <p>
            If we change this policy we will update the effective date shown at the top of this page and
            publish the revised version here. Where a change materially affects how we handle data
            obtained through Google APIs, we will describe that change in the updated text.
        </p>

        <h2>11. Contact</h2>
        <p>
            Questions, complaints or requests about this policy:
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
        </p>

    </div>
</section>

@endsection
