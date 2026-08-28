<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel AIWMWeb - Database Setup</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0b0f17; color: #f9fafb; font-family: Arial, sans-serif; }
        main { width: min(680px, 90vw); padding: 32px; border: 1px solid #243244; border-radius: 18px; background: #111827; box-shadow: 0 24px 70px #0008; }
        h1 { margin-top: 0; }
        p, li { color: #d1d5db; line-height: 1.6; }
        code { color: #a7f3d0; }
        .status { padding: 14px; border-radius: 10px; background: #1f2937; margin: 20px 0; }
    </style>
</head>
<body>
<main>
    <h1>Database setup required</h1>
    <p>Laravel AIWMWeb cannot enter the application workspace until its configured database is reachable and the migration ledger is present.</p>

    <div class="status">
        <strong>Configured driver:</strong> {{ $status['driver'] }}<br>
        <strong>Database reachable:</strong> {{ $status['database_reachable'] ? 'yes' : 'no' }}<br>
        <strong>Migrations ready:</strong> {{ $status['migrations_ready'] ? 'yes' : 'no' }}
    </div>

    <ol>
        <li>Configure the database connection through the deployment environment.</li>
        <li>Run <code>php artisan migrate --force</code> from the Laravel backend directory.</li>
        <li>Reload this page; once setup is complete, it redirects to the application landing page.</li>
    </ol>

    <p>No database password, connection string, or other secret is rendered by this endpoint.</p>
</main>
</body>
</html>
