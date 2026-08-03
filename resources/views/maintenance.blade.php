<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Aplicación en mantención — {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #2563eb;
            --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: radial-gradient(circle at top, #dbeafe 0%, var(--bg) 55%);
            color: var(--text);
        }
        main {
            width: 100%;
            max-width: 32rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 2rem 1.75rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }
        .badge {
            display: inline-block;
            margin-bottom: 1rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: #eff6ff;
            color: var(--accent);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.5rem;
            line-height: 1.3;
        }
        p {
            margin: 0;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <main>
        <div class="badge">Mantención</div>
        <h1>Aplicación en mantención</h1>
        <p>La aplicación está en mantención. Estará disponible en unos minutos.</p>
    </main>
</body>
</html>
