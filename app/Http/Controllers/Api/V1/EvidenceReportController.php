<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EvidenceSession;
use App\Services\ForensicReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class EvidenceReportController extends Controller
{
    /**
     * Stream the forensic chain-of-custody PDF as a clean binary attachment.
     *
     * Purges any PHP output-buffer content (from DomPDF debug output or Blade
     * partials) before committing to stream so the binary payload is never
     * corrupted. Validates the %PDF magic header before streaming and returns a
     * clean JSON error otherwise — this means the JS fetch handler always sees
     * !response.ok rather than a blob that starts with HTML.
     *
     * Access-Control-Expose-Headers covers Content-Disposition and Content-Length
     * so the browser's fetch() layer can read the filename and size even when the
     * request crosses an origin boundary (e.g. Cloudflare termination).
     */
    public function downloadPdf(Request $request, string $sessionId, ForensicReportService $reports): Response|JsonResponse
    {
        $session = EvidenceSession::with(['chunks', 'auditLogs', 'user'])
            ->whereKey($sessionId)
            ->ownedBy($request->user())
            ->firstOrFail();

        // Flush any stray output-buffer content accumulated by DomPDF/Blade so
        // it never contaminates the binary PDF stream.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        try {
            $pdfOutput = $reports->generateReport($session);
        } catch (\Throwable $exception) {
            return response()->json([
                'error' => 'Failed to generate forensic PDF: ' . $exception->getMessage(),
            ], HttpFoundationResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($pdfOutput === '' || strlen($pdfOutput) < 5 || ! str_starts_with($pdfOutput, '%PDF')) {
            return response()->json([
                'error' => 'Forensic PDF renderer returned an invalid payload (not a valid PDF stream).',
            ], HttpFoundationResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip'   => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action'     => 'report.generated',
        ]);

        $filename = 'proofvault-forensic-' . $session->id . '.pdf';

        return response($pdfOutput, HttpFoundationResponse::HTTP_OK, [
            'Content-Type'                  => 'application/pdf',
            'Content-Disposition'           => 'attachment; filename="' . $filename . '"',
            'Content-Length'                => (string) strlen($pdfOutput),
            'Content-Transfer-Encoding'     => 'binary',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Length',
            'Cache-Control'                 => 'no-store, no-cache, must-revalidate, private',
            'Pragma'                        => 'no-cache',
            'X-Content-Type-Options'        => 'nosniff',
            'X-WORM-Policy'                 => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Alias retained for existing clients that call exportForensicPdf.
     */
    public function exportForensicPdf(Request $request, string $sessionId, ForensicReportService $reports): Response|JsonResponse
    {
        return $this->downloadPdf($request, $sessionId, $reports);
    }
}
