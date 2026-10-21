<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex">
        <title>Stoop reader insights · {{ $report['from'] }} to {{ $report['to'] }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="mx-auto max-w-4xl bg-paper p-6 font-sans text-[14px] text-ink">
        <div class="flex items-baseline justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-mute">Stoop · DC, block by block</p>
                <h1 class="font-display text-3xl font-bold">Reader insights</h1>
                <p class="text-mute">{{ \Illuminate\Support\Carbon::parse($report['from'])->format('M j, Y') }} to {{ \Illuminate\Support\Carbon::parse($report['to'])->format('M j, Y') }}</p>
            </div>
            <button type="button" onclick="window.print()" class="rounded-lg border border-rule px-3 py-1.5 print:hidden">Print or save as PDF</button>
        </div>

        @include('partials.insights-tables', ['report' => $report])
    </body>
</html>
