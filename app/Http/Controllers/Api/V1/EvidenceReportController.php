<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EvidenceSession;
use App\Services\ForensicReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

class EvidenceReportController extends Controller
{
    /**
     * Stream the forensic chain-of-custody PDF as a binary attachment.
     */
    public function downloadPdf(Request $request, string $sessionId, ForensicReportService $reports): Response
    {
        $session = EvidenceSession::findOrFail($sessionId);

        $pdfContent = $reports->generateReport($session);

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'report.generated',
        ]);

        return response($pdfContent, HttpFoundationResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="proofvault-forensic-'.$session->id.'.pdf"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }
}
