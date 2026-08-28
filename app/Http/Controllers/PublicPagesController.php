<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Public, unauthenticated pages: the marketing home page, the Privacy Policy
 * and the Terms of Service.
 *
 * These three URLs are the ones registered on the Google Cloud OAuth consent
 * screen (App domain → home page / privacy policy / terms of service), so they
 * must stay publicly reachable without a login and on the same host as the
 * platform. Do not move them behind the `auth` middleware.
 */
class PublicPagesController extends Controller
{
    /** Published contact address for privacy and legal enquiries. */
    private const CONTACT_EMAIL = 'histopathology.2026.3.5@gmail.com';

    /**
     * Effective date of the published Privacy Policy and Terms.
     * This is a fact about the documents, not "today" — bump it by hand, and
     * only when the wording of those documents actually changes.
     */
    private const EFFECTIVE_DATE = '28 August 2026';

    public function home(): View
    {
        return view('public.home', $this->shared());
    }

    public function privacy(): View
    {
        return view('public.privacy', $this->shared());
    }

    public function terms(): View
    {
        return view('public.terms', $this->shared());
    }

    /**
     * @return array{contactEmail:string, effectiveDate:string, domain:string}
     */
    private function shared(): array
    {
        return [
            'contactEmail'  => self::CONTACT_EMAIL,
            'effectiveDate' => self::EFFECTIVE_DATE,
            // Taken from APP_URL so the documents always name the host they are
            // actually served from.
            'domain'        => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'ai.histopathology.cloud',
        ];
    }
}
