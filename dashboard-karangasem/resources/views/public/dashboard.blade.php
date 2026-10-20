<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <meta name="theme-color" content="#082A4F">
    <title>Dashboard Publik — PADMA Karangasem</title>
    <style>
        :root {
            --brand-950: #041b33;
            --brand-800: #082a4f;
            --brand-700: #0d5ca8;
            --gold-300: #f2c94c;
            --slate-200: #e2e8f0;
            --slate-600: #475569;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: var(--brand-950);
            background: #f8fbff;
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, sans-serif;
        }

        header {
            display: flex;
            min-height: 4.25rem;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.7rem 1rem;
            border-bottom: 1px solid var(--slate-200);
            background: #ffffff;
        }

        .brand {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 0.7rem;
            color: inherit;
            text-decoration: none;
        }

        .brand img {
            height: 2.15rem;
            width: auto;
            object-fit: contain;
        }

        .brand-divider {
            width: 1px;
            height: 1.9rem;
            background: var(--slate-200);
        }

        .brand-copy {
            display: flex;
            min-width: 0;
            flex-direction: column;
        }

        .brand-title {
            font-size: 0.92rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            white-space: nowrap;
        }

        .brand-subtitle {
            overflow: hidden;
            color: var(--slate-600);
            font-size: 0.68rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .actions {
            display: flex;
            flex-shrink: 0;
            align-items: center;
            gap: 0.5rem;
        }

        .back-link,
        .login-link {
            display: inline-flex;
            min-height: 2.5rem;
            align-items: center;
            justify-content: center;
            padding: 0.45rem 0.8rem;
            border-radius: 0.65rem;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
        }

        .back-link {
            color: var(--slate-600);
        }

        .back-link:hover {
            background: #f1f5fb;
            color: var(--brand-800);
        }

        .login-link {
            border: 1px solid #cfe0f7;
            color: var(--brand-700);
            background: #ffffff;
        }

        .login-link:hover {
            background: #eef5ff;
        }

        a:focus-visible {
            outline: 2px solid var(--brand-700);
            outline-offset: 2px;
        }

        iframe {
            display: block;
            width: 100%;
            height: calc(100vh - 4.25rem);
            border: 0;
            background: #f8fbff;
        }

        @media (max-width: 560px) {
            .brand-subtitle,
            .back-link {
                display: none;
            }

            .brand-title {
                font-size: 0.82rem;
                white-space: normal;
            }
        }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ route('home') }}" aria-label="PADMA Karangasem — kembali ke beranda">
            <img src="{{ asset('images/branding/logo-disdikpora.webp') }}" width="441" height="114" alt="Logo Disdikpora Kabupaten Karangasem">
            <span class="brand-divider" aria-hidden="true"></span>
            <img src="{{ asset('images/branding/padma-mark-256.webp') }}" width="256" height="256" alt="Logo PADMA">
            <span class="brand-copy">
                <span class="brand-title">Dashboard Publik PADMA</span>
                <span class="brand-subtitle">Data pendidikan Kabupaten Karangasem</span>
            </span>
        </a>

        <div class="actions">
            <a class="back-link" href="{{ route('home') }}">Beranda</a>
            <a class="login-link" href="{{ route('login') }}">Login VIP</a>
        </div>
    </header>

    <iframe
        id="public-dashboard-frame"
        src="{{ $embedUrl }}"
        title="Dashboard publik data pendidikan Kabupaten Karangasem"
        loading="lazy"
        referrerpolicy="strict-origin-when-cross-origin"
        frameborder="0"
        allowfullscreen
    ></iframe>
</body>
</html>
