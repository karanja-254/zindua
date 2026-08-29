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
     * Stream the forensic chain-of-custody PDF as a binary attachment.
     */
    public function downloadPdf(Request $request, string $sessionId, ForensicReportService $reports): Response|JsonResponse
    {
        $session = EvidenceSession::findOrFail($sessionId);

        try {
            $pdfOutput = $reports->generateReport($session);
        } catch (\Throwable $exception) {
            return response()->json([
                'error' => 'Failed to generate forensic PDF.',
            ], HttpFoundationResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($pdfOutput === '' || ! str_starts_with($pdfOutput, '%PDF')) {
            return response()->json([
                'error' => 'Forensic PDF renderer returned an invalid payload.',
            ], HttpFoundationResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'report.generated',
        ]);

        return response($pdfOutput, HttpFoundationResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="proofvault-forensic-'.$session->id.'.pdf"',
            'Content-Length' => (string) strlen($pdfOutput),
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Alias for clients that call exportForensicPdf.
     */
    public function exportForensicPdf(Request $request, string $sessionId, ForensicReportService $reports): Response|JsonResponse
    {
        return $this->downloadPdf($request, $sessionId, $reports);
    }
}
