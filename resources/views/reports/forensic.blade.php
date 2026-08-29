<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ProofVault Forensic Report — {{ $session->id }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; font-size: 11px; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 2px; color: #0f5132; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 2px solid #0f5132; padding-bottom: 3px; color: #0f5132; }
        .muted { color: #666; font-size: 10px; }
        .banner { background: #0f5132; color: #fff; padding: 12px 16px; }
        .content { padding: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #cbd5cb; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #e7f1eb; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
        td { font-size: 10px; }
        .hash { font-family: DejaVu Sans Mono, monospace; font-size: 8px; word-break: break-all; }
        .kv td:first-child { width: 30%; font-weight: bold; background: #f4f7f5; }
        .ok { color: #0f5132; font-weight: bold; }
        .fail { color: #b02a37; font-weight: bold; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-weight: bold; text-transform: uppercase; }
        .risk-high { background: #b02a37; color: #fff; }
        .risk-medium { background: #fd7e14; color: #fff; }
        .risk-low { background: #ffc107; color: #1a1a1a; }
        .risk-unassessed { background: #adb5bd; color: #1a1a1a; }
    </style>
</head>
<body>
    <div class="banner">
        <h1>ProofVault — Forensic Chain-of-Custody Report</h1>
        <div>Emergency Evidence Preservation Ledger</div>
    </div>

    <div class="content">
        <p class="muted">Generated: {{ $generatedAt }}</p>

        <h2>Session Metadata</h2>
        <table class="kv">
            <tr><td>Session UUID</td><td class="hash">{{ $session->id }}</td></tr>
            <tr><td>Investigator / Preserver</td><td>{{ $investigatorName }}</td></tr>
            <tr><td>Status</td><td>{{ ucfirst($session->status) }}</td></tr>
            <tr>
                <td>Assessed Risk Level</td>
                <td><span class="badge risk-{{ $session->risk_level }}">{{ $session->risk_level }}</span></td>
            </tr>
            <tr><td>Started (EAT)</td><td>{{ $startedAt }}</td></tr>
            <tr><td>Finalized (EAT)</td><td>{{ $finalizedAt }}</td></tr>
            <tr><td>Total Evidence Chunks</td><td>{{ count($ledger) }}</td></tr>
            <tr>
                <td>Chain Integrity</td>
                <td>
                    @if ($chainVerified)
                        <span class="ok">VERIFIED — cumulative hash chain intact</span>
                    @else
                        <span class="fail">INTEGRITY FAILURE — chain mismatch detected</span>
                    @endif
                </td>
            </tr>
            <tr><td>Final Cumulative Hash</td><td class="hash">{{ $finalChainHash }}</td></tr>
        </table>

        <h2>Cryptographic Ledger (SHA-256)</h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Captured (EAT)</th>
                    <th>Bytes</th>
                    <th>Chunk Hash</th>
                    <th>Cumulative Hash</th>
                    <th>Verify</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledger as $row)
                    <tr>
                        <td>{{ $row['sequence'] }}</td>
                        <td>{{ $row['captured_at'] }}</td>
                        <td>{{ $row['byte_size'] }}</td>
                        <td class="hash">{{ $row['chunk_hash'] }}</td>
                        <td class="hash">{{ $row['cumulative_hash'] }}</td>
                        <td>
                            @if ($row['verified'])
                                <span class="ok">OK</span>
                            @else
                                <span class="fail">FAIL</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No evidence chunks recorded for this session.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>GPS Trail &amp; Accuracy</h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Latitude</th>
                    <th>Longitude</th>
                    <th>Accuracy (m)</th>
                    <th>Captured (EAT)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledger as $row)
                    <tr>
                        <td>{{ $row['sequence'] }}</td>
                        <td>{{ $row['latitude'] ?? '—' }}</td>
                        <td>{{ $row['longitude'] ?? '—' }}</td>
                        <td>{{ $row['accuracy_meters'] ?? '—' }}</td>
                        <td>{{ $row['captured_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No GPS data recorded.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>AI Threat Indicator Breakdown</h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Weapon</th>
                    <th>Violence</th>
                    <th>Acoustic Distress</th>
                    <th>Risk</th>
                    <th>Assessed (EAT)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledger as $row)
                    @php($ai = $row['ai_indicators'] ?? null)
                    <tr>
                        <td>{{ $row['sequence'] }}</td>
                        @if ($ai)
                            <td>{{ number_format(((float) ($ai['weapon'] ?? 0)) * 100, 1) }}%</td>
                            <td>{{ number_format(((float) ($ai['violence'] ?? 0)) * 100, 1) }}%</td>
                            <td>{{ number_format(((float) ($ai['acoustic_distress'] ?? 0)) * 100, 1) }}%</td>
                            <td><span class="badge risk-{{ $ai['risk_level'] ?? 'unassessed' }}">{{ $ai['risk_level'] ?? 'unassessed' }}</span></td>
                            <td>
                                @if (!empty($ai['assessed_at']))
                                    {{ \Illuminate\Support\Carbon::parse($ai['assessed_at'])->timezone('Africa/Nairobi')->format('Y-m-d H:i:s') }}
                                @else
                                    —
                                @endif
                            </td>
                        @else
                            <td colspan="5" class="muted">Not yet assessed</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="6">No evidence chunks recorded.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>AI Risk &amp; Audit Log</h2>
        <table>
            <thead>
                <tr>
                    <th>Timestamp (EAT)</th>
                    <th>Action</th>
                    <th>Actor IP</th>
                    <th>User Agent</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($auditLogs as $log)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($log->created_at)->timezone('Africa/Nairobi')->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $log->action }}</td>
                        <td>{{ $log->actor_ip ?? '—' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $log->user_agent, 60) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No audit events recorded.</td></tr>
                @endforelse
            </tbody>
        </table>

        <p class="muted" style="margin-top: 24px;">
            This document is a WORM (Write-Once, Read-Many) forensic artifact. Evidence records referenced
            herein are immutable. Any discrepancy in the cumulative hash chain indicates tampering.
        </p>
    </div>
</body>
</html>
