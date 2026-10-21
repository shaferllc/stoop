<?php

namespace App\Http\Controllers;

use App\Livewire\Admin\Insights;
use App\Support\Audit;
use App\Support\ReaderInsights;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reader insights report as a CSV download and as a printable page (print to PDF).
 */
class InsightsController extends Controller
{
    public function csv(Request $request): StreamedResponse
    {
        $report = $this->report($request);
        Audit::log('insights.exported', null, ['from' => $report['from'], 'to' => $report['to']]);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            foreach (ReaderInsights::csvRows($report) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "stoop-reader-insights-{$report['from']}-{$report['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function print(Request $request): View
    {
        return view('admin.insights-print', ['report' => $this->report($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function report(Request $request): array
    {
        abort_unless(ReaderInsights::allowed($request->user()), 403);

        return ReaderInsights::report(...Insights::period((string) $request->query('from', ''), (string) $request->query('to', '')));
    }
}
