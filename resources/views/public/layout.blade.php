<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · HCMA AI</title>
<meta name="description" content="@yield('description', 'HCMA AI builds MAIND PATH, a research platform for computational analysis of whole-slide pathology images.')">
<link rel="icon" href="{{ asset('brand/hcma-ai-logo.png') }}" type="image/png">
<style>
    :root{
        /* Brand palette, sampled from the HCMA AI logo */
        --brand:#36B82A;          /* primary green (wordmark) */
        --brand-dark:#2A8F21;
        --brand-deep:#0B3D14;     /* deep green for dark surfaces */
        --brand-deeper:#062B0E;
        --spring:#0AE781;         /* bright accent */
        --mint:#52FF9B;           /* light accent */
        --ink:#0F1911;
        --body:#3C4A3F;
        --muted:#6B7A6E;
        --line:#DCE6DD;
        --surface:#FFFFFF;
        --wash:#F4F9F4;
        --radius:14px;
        --max:1080px;
    }
    *{box-sizing:border-box;}
    html{-webkit-text-size-adjust:100%;}
    body{
        margin:0;
        background:var(--surface);
        color:var(--body);
        font:400 17px/1.72 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
        -webkit-font-smoothing:antialiased;
    }
    a{color:var(--brand-dark);}
    .wrap{max-width:var(--max);margin:0 auto;padding:0 24px;}

    /* ── Header ───────────────────────────────────────────── */
    header.site{border-bottom:1px solid var(--line);background:var(--surface);position:sticky;top:0;z-index:20;}
    .bar{display:flex;align-items:center;gap:24px;padding:16px 0;flex-wrap:wrap;}
    .logo img{height:38px;width:auto;display:block;}
    .logo{display:flex;align-items:center;text-decoration:none;}
    nav.site{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    nav.site a{
        text-decoration:none;color:var(--body);font-size:15px;font-weight:500;
        padding:8px 14px;border-radius:8px;
    }
    nav.site a:hover{background:var(--wash);color:var(--brand-dark);}
    nav.site a.cta{background:var(--brand);color:#fff;}
    nav.site a.cta:hover{background:var(--brand-dark);color:#fff;}

    /* ── Hero ─────────────────────────────────────────────── */
    .hero{
        background:
            radial-gradient(1100px 380px at 82% -12%, rgba(10,231,129,.20), transparent 62%),
            linear-gradient(160deg,var(--brand-deeper) 0%,var(--brand-deep) 58%,#12551E 100%);
        color:#EAF7EC;padding:76px 0 68px;
    }
    .hero h1{
        margin:0 0 18px;font-size:clamp(30px,4.6vw,50px);line-height:1.14;
        letter-spacing:-.02em;font-weight:700;color:#fff;
    }
    .hero .kicker{
        display:inline-block;font-size:13px;font-weight:700;letter-spacing:.14em;
        text-transform:uppercase;color:var(--mint);margin-bottom:20px;
    }
    .hero p{margin:0;font-size:19px;max-width:none;color:#C9E4CD;}
    .hero .lede{max-width:660px;}
    .hero-actions{margin-top:32px;display:flex;gap:12px;flex-wrap:wrap;}
    .btn{
        display:inline-block;text-decoration:none;font-weight:600;font-size:16px;
        padding:13px 26px;border-radius:10px;border:1px solid transparent;
    }
    .btn-primary{background:var(--spring);color:#04310F;}
    .btn-primary:hover{background:var(--mint);}
    .btn-ghost{border-color:rgba(255,255,255,.34);color:#fff;}
    .btn-ghost:hover{background:rgba(255,255,255,.10);}

    /* ── Sections ─────────────────────────────────────────── */
    section{padding:60px 0;}
    section.tint{background:var(--wash);border-top:1px solid var(--line);border-bottom:1px solid var(--line);}
    h2{font-size:clamp(23px,2.7vw,31px);line-height:1.24;color:var(--ink);margin:0 0 14px;letter-spacing:-.01em;font-weight:700;}
    h3{font-size:19px;color:var(--ink);margin:32px 0 10px;font-weight:650;}
    p{margin:0 0 16px;}
    ul{margin:0 0 16px;padding-left:22px;}
    li{margin-bottom:9px;}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(268px,1fr));gap:20px;margin-top:32px;}
    .card{
        background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
        padding:26px;
    }
    .card h3{margin:0 0 8px;font-size:17px;}
    .card p{margin:0;font-size:15.5px;color:var(--muted);}
    .card .mark{
        width:38px;height:38px;border-radius:9px;margin-bottom:16px;
        background:linear-gradient(135deg,var(--brand),var(--spring));
    }

    /* ── Legal documents ──────────────────────────────────── */
    .doc{max-width:790px;}
    .doc h2{margin-top:44px;padding-top:4px;}
    .doc h2:first-of-type{margin-top:0;}
    .doc ul{margin-bottom:18px;}
    .doc li{margin-bottom:11px;}
    .page-head{border-bottom:1px solid var(--line);padding:52px 0 34px;}
    .page-head h1{font-size:clamp(28px,3.8vw,40px);color:var(--ink);margin:0 0 12px;letter-spacing:-.02em;font-weight:700;}
    .page-head .meta{color:var(--muted);font-size:15px;margin:0;}
    .callout{
        background:var(--wash);border:1px solid var(--line);border-left:4px solid var(--brand);
        border-radius:10px;padding:20px 22px;margin:24px 0;
    }
    .callout p:last-child{margin-bottom:0;}
    .callout strong{color:var(--ink);}
    table.terms{border-collapse:collapse;width:100%;margin:22px 0;font-size:15.5px;}
    table.terms th,table.terms td{border:1px solid var(--line);padding:12px 14px;text-align:left;vertical-align:top;}
    table.terms th{background:var(--wash);color:var(--ink);font-weight:650;}
    code{
        background:var(--wash);border:1px solid var(--line);border-radius:5px;
        padding:1px 6px;font-size:14.5px;
        font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
        color:var(--brand-dark);word-break:break-all;
    }

    /* ── Footer ───────────────────────────────────────────── */
    footer.site{background:var(--brand-deeper);color:#A9C6AE;padding:44px 0;font-size:15px;}
    footer.site a{color:var(--mint);text-decoration:none;}
    footer.site a:hover{text-decoration:underline;}
    .foot{display:flex;gap:28px;flex-wrap:wrap;align-items:center;}
    .foot .links{margin-left:auto;display:flex;gap:22px;flex-wrap:wrap;}
    .foot .tm{color:#7E9C84;font-size:14px;margin-top:14px;}

    @media (max-width:620px){
        body{font-size:16px;}
        .hero{padding:52px 0 46px;}
        section{padding:44px 0;}
        .foot .links{margin-left:0;}
    }
</style>
</head>
<body>

<header class="site">
    <div class="wrap bar">
        <a class="logo" href="{{ route('public.home') }}">
            <img src="{{ asset('brand/hcma-ai-logo.png') }}" alt="HCMA AI">
        </a>
        <nav class="site">
            <a href="{{ route('public.home') }}">Home</a>
            <a href="{{ route('public.privacy') }}">Privacy</a>
            <a href="{{ route('public.terms') }}">Terms</a>
            <a class="cta" href="{{ route('admin.dashboard') }}">Platform sign-in</a>
        </nav>
    </div>
</header>

@yield('body')

<footer class="site">
    <div class="wrap">
        <div class="foot">
            <div>&copy; {{ date('Y') }} HCMA AI. All rights reserved.</div>
            <div class="links">
                <a href="{{ route('public.home') }}">Home</a>
                <a href="{{ route('public.privacy') }}">Privacy Policy</a>
                <a href="{{ route('public.terms') }}">Terms of Service</a>
                <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
            </div>
        </div>
        <p class="tm">
            HCMA AI&trade; and MAIND PATH&trade; are trademarks of HCMA AI, Kingdom of Saudi Arabia.
            MAIND PATH is a research platform. It is not a medical device and is not intended
            for clinical diagnosis or treatment decisions.
        </p>
    </div>
</footer>

</body>
</html>
