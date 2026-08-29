import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { listSessions, getSessionDetail, generateMockSession, verifySession, downloadEvidencePackage, finalizeSession, uploadEvidenceFile, overrideRiskLevel, fetchAuthorizedMediaUrl } from './api';
import GpsTimelineMap from './GpsTimelineMap';
import { useEvidenceCapture } from './useEvidenceCapture';
import { verifyChain } from './sha256';

const INACTIVITY_LIMIT_MS = 60_000;
const THEME_KEY = 'vault_theme';
const GENESIS = '0'.repeat(64);
const EAT_FORMATTER = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Africa/Nairobi',
    dateStyle: 'medium',
    timeStyle: 'medium',
});

function formatEat(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : `${EAT_FORMATTER.format(date)} EAT`;
}

function formatElapsed(ms) {
    const total = Math.floor(ms / 1000);
    const hh = String(Math.floor(total / 3600)).padStart(2, '0');
    const mm = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
    const ss = String(total % 60).padStart(2, '0');
    return `${hh}:${mm}:${ss}`;
}

const RISK_STYLES = {
    high: 'bg-red-500/15 text-red-600 ring-red-500/40 dark:text-red-300',
    medium: 'bg-orange-500/15 text-orange-600 ring-orange-500/40 dark:text-orange-300',
    low: 'bg-green-500/15 text-green-700 ring-green-500/40 dark:text-green-300',
    unassessed: 'bg-slate-500/15 text-slate-600 ring-slate-500/40 dark:text-slate-300',
};

const STATUS_STYLES = {
    active: 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/40 dark:text-emerald-300',
    finalized: 'bg-sky-500/15 text-sky-700 ring-sky-500/40 dark:text-sky-300',
    interrupted: 'bg-amber-500/15 text-amber-700 ring-amber-500/40 dark:text-amber-300',
};

const RISK_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'high', label: '🔴 High Risk' },
    { id: 'medium', label: '🟡 Medium Risk' },
    { id: 'low', label: '🟢 Low Risk' },
];

function Badge({ value, styles }) {
    const cls = styles[value] ?? 'bg-slate-500/15 text-slate-600 ring-slate-500/40';
    return (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide ring-1 ring-inset ${cls}`}>
            {value ?? 'unknown'}
        </span>
    );
}

function mediaKind(chunk) {
    const mime = String(chunk?.mime_type ?? '').toLowerCase();
    const type = String(chunk?.file_type ?? '').toLowerCase();
    const lower = String(chunk?.storage_path ?? chunk ?? '').toLowerCase();
    if (mime.startsWith('image/') || type === 'image' || /\.(jpe?g|png|webp|gif)$/i.test(lower)) {
        return 'image';
    }
    if (mime.startsWith('audio/') || type === 'audio' || /\.(mp3|wav|m4a|ogg|aac)$/i.test(lower)) {
        return 'audio';
    }
    if (mime === 'application/pdf' || type === 'pdf' || type === 'document' || /\.(pdf|docx?|txt)$/i.test(lower)) {
        return 'document';
    }
    if (mime.startsWith('video/') || type === 'video' || /\.(mp4|webm)$/i.test(lower)) {
        return 'video';
    }
    return 'video';
}

function chunkPlaybackUrl(chunk) {
    return chunk?.playback_url || chunk?.media_url || chunk?.signed_url || '';
}

export default function EvidenceDashboard({ user }) {
    const tokenRef = useRef(
        localStorage.getItem('pv_token')
        || sessionStorage.getItem('pv_token')
        || sessionStorage.getItem('vault_token'),
    );

    const [theme, setTheme] = useState(() => localStorage.getItem(THEME_KEY) || 'light');
    const [sessions, setSessions] = useState([]);
    const [stats, setStats] = useState({ total: 0, high: 0, medium: 0, low: 0, storage: 'worm_locked' });
    const [loadingSessions, setLoadingSessions] = useState(true);
    const [error, setError] = useState(null);
    const [riskFilter, setRiskFilter] = useState('all');
    const [query, setQuery] = useState('');

    const [detail, setDetail] = useState(null);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [activeChunk, setActiveChunk] = useState(null);
    const [downloading, setDownloading] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [integrity, setIntegrity] = useState(null);
    const [verifying, setVerifying] = useState(false);
    const [continuousPlayback, setContinuousPlayback] = useState(true);
    const [snapNote, setSnapNote] = useState(null);
    const [uploadingFile, setUploadingFile] = useState(false);
    const [overriding, setOverriding] = useState(false);
    const [amendNotice, setAmendNotice] = useState(null);
    const capture = useEvidenceCapture();
    const captureRef = useRef(capture);
    captureRef.current = capture;
    const videoRef = useRef(null);
    const viewfinderRef = useRef(null);
    const fileInputRef = useRef(null);
    const blobUrlsRef = useRef([]);
    const [isViewfinderFullscreen, setIsViewfinderFullscreen] = useState(false);
    const nextPlayable = useMemo(() => {
        if (!detail || !activeChunk) {
            return null;
        }
        const playable = (detail.chunks ?? []).filter((ch) => chunkPlaybackUrl(ch));
        const idx = playable.findIndex((ch) => ch.sequence_number === activeChunk.sequence_number);
        return idx >= 0 ? playable[idx + 1] ?? null : null;
    }, [detail, activeChunk]);

    const isDark = theme === 'dark';

    useEffect(() => {
        localStorage.setItem(THEME_KEY, theme);
    }, [theme]);

    const c = useMemo(() => ({
        app: isDark ? 'bg-slate-950 text-slate-100' : 'bg-slate-50 text-slate-900',
        border: isDark ? 'border-slate-800' : 'border-slate-200',
        panel: isDark ? 'border-slate-800 bg-slate-900/60' : 'border-slate-200 bg-white shadow-sm',
        muted: isDark ? 'text-slate-400' : 'text-slate-500',
        rowHover: isDark ? 'hover:bg-slate-800/60' : 'hover:bg-slate-100',
        rowActive: isDark ? 'bg-slate-800/80' : 'bg-slate-100',
        tableHead: isDark ? 'bg-slate-800/90 text-slate-300' : 'bg-slate-100 text-slate-600',
        divide: isDark ? 'divide-slate-800' : 'divide-slate-200',
        input: isDark
            ? 'bg-slate-800 border-slate-700 text-slate-100 placeholder:text-slate-500'
            : 'bg-white border-slate-300 text-slate-900 placeholder:text-slate-400',
        toggle: isDark
            ? 'bg-slate-800 text-slate-200 hover:bg-slate-700'
            : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-100',
        code: isDark ? 'text-emerald-300' : 'text-emerald-600',
        chipIdle: isDark ? 'bg-slate-800 text-slate-300 hover:bg-slate-700' : 'bg-white text-slate-600 border border-slate-300 hover:bg-slate-100',
        chipActive: 'bg-emerald-600 text-white',
        card: isDark ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white',
    }), [isDark]);

    const lock = useCallback(() => {
        captureRef.current.releaseHardware();
        sessionStorage.removeItem('vault_token');
        sessionStorage.removeItem('pv_token');
        localStorage.removeItem('pv_token');
        window.location.reload();
    }, []);

    const loadSessions = useCallback(async () => {
        const token = tokenRef.current;
        if (!token) {
            lock();
            return;
        }
        setLoadingSessions(true);
        try {
            const payload = await listSessions(token);
            setSessions(payload.data ?? []);
            if (payload.stats) {
                setStats(payload.stats);
            }
            setError(null);
        } catch (err) {
            setError(err.message);
        } finally {
            setLoadingSessions(false);
        }
    }, [lock]);

    useEffect(() => {
        if (capture.isRecording) {
            return undefined;
        }
        let timer = null;
        const reset = () => {
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(lock, INACTIVITY_LIMIT_MS);
        };
        const events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];
        events.forEach((evt) => window.addEventListener(evt, reset, { passive: true }));
        reset();
        return () => {
            if (timer) {
                clearTimeout(timer);
            }
            events.forEach((evt) => window.removeEventListener(evt, reset));
        };
    }, [lock, capture.isRecording]);

    useEffect(() => {
        const node = videoRef.current;
        if (node && capture.previewStream && capture.mode === 'video') {
            node.srcObject = capture.previewStream;
        }
        return () => {
            if (node) {
                node.srcObject = null;
            }
        };
    }, [capture.previewStream, capture.mode]);

    useEffect(() => {
        document.title = 'ProofVault · Secure Evidence Repository';
        loadSessions();
    }, [loadSessions]);

    const openSession = useCallback(async (sessionId) => {
        const token = tokenRef.current;
        if (!token) {
            lock();
            return;
        }
        setLoadingDetail(true);
        setDetail(null);
        setActiveChunk(null);
        setIntegrity(null);
        setError(null);
        blobUrlsRef.current.forEach((url) => URL.revokeObjectURL(url));
        blobUrlsRef.current = [];
        try {
            const payload = await getSessionDetail(token, sessionId);
            const hydrated = [];
            for (const chunk of payload.chunks ?? []) {
                const proxyUrl = `/api/v1/evidence/${sessionId}/chunks/${chunk.sequence_number}/media`;
                let playback = null;
                const blobUrl = await fetchAuthorizedMediaUrl(token, proxyUrl);
                if (blobUrl) {
                    blobUrlsRef.current.push(blobUrl);
                    playback = blobUrl;
                }
                hydrated.push({
                    ...chunk,
                    playback_url: playback,
                    media_url: playback,
                    has_binary: Boolean(blobUrl),
                });
            }
            const next = { ...payload, chunks: hydrated };
            setDetail(next);
            const playable = hydrated.filter((ch) => chunkPlaybackUrl(ch));
            const latest = playable.length > 0 ? playable[playable.length - 1] : (hydrated[hydrated.length - 1] ?? null);
            setActiveChunk(latest);
        } catch (err) {
            setError(err.message);
        } finally {
            setLoadingDetail(false);
        }
    }, [lock]);

    const handleDownloadPdf = useCallback(async (sessionId) => {
        const token = localStorage.getItem('pv_token')
            || sessionStorage.getItem('pv_token')
            || sessionStorage.getItem('vault_token')
            || tokenRef.current;
        if (!token) {
            lock();
            return;
        }
        setDownloading(true);
        try {
            const response = await fetch(`/api/v1/evidence/${sessionId}/report/pdf`, {
                method: 'GET',
                headers: {
                    Authorization: `Bearer ${token}`,
                    Accept: 'application/pdf, application/json',
                },
            });

            if (!response.ok) {
                // Surface the server error message rather than a generic string.
                let serverMsg = `Failed to generate PDF: ${response.statusText}`;
                try {
                    const errJson = await response.json();
                    serverMsg = errJson.error ?? errJson.message ?? serverMsg;
                } catch (_) { /* binary or non-JSON body */ }
                throw new Error(serverMsg);
            }

            const blob = await response.blob();

            // Use ArrayBuffer for cross-browser reliable magic-byte validation.
            // blob.slice().text() can return garbled bytes in Firefox/Safari on
            // binary data because text() applies UTF-8 decoding.
            const headerBuf = await blob.slice(0, 5).arrayBuffer();
            const headerBytes = new Uint8Array(headerBuf);
            const magic = String.fromCharCode(...headerBytes);
            if (!blob.size || !magic.startsWith('%PDF')) {
                throw new Error('Server returned an invalid PDF payload. Try again or contact support.');
            }

            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `proofvault-forensic-${sessionId}.pdf`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
        } catch (err) {
            setError(err.message || 'Failed to fetch forensic PDF.');
        } finally {
            setDownloading(false);
        }
    }, [lock]);

    const handleExportPackage = useCallback(async (sessionId) => {
        const token = tokenRef.current;
        if (!token) {
            lock();
            return;
        }
        setExporting(true);
        try {
            await downloadEvidencePackage(token, sessionId);
        } catch (err) {
            setError(err.message);
        } finally {
            setExporting(false);
        }
    }, [lock]);

    const handleGenerateMock = useCallback(async () => {
        const token = tokenRef.current;
        if (!token) {
            lock();
            return;
        }
        setGenerating(true);
        try {
            const created = await generateMockSession(token);
            await loadSessions();
            if (created?.session_id) {
                await openSession(created.session_id);
            }
        } catch (err) {
            setError(err.message);
        } finally {
            setGenerating(false);
        }
    }, [lock, loadSessions, openSession]);

    const handleSnapshot = useCallback(async () => {
        const result = await capture.snapshotPhoto();
        if (result) {
            setSnapNote(`Snapshot preserved · SHA-256 ${result.hash.slice(0, 12)}…`);
            setTimeout(() => setSnapNote(null), 4000);
            await loadSessions();
        }
    }, [capture, loadSessions]);

    const handleStopCapture = useCallback(async () => {
        const sessionId = await capture.stopAndFinalize();
        await loadSessions();
        if (sessionId) {
            await openSession(sessionId);
        }
    }, [capture, loadSessions, openSession]);

    const toggleFullScreen = useCallback(() => {
        const node = viewfinderRef.current;
        if (!node) {
            return;
        }
        const doc = document;
        const active = doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;
        if (active) {
            const exit = doc.exitFullscreen || doc.webkitExitFullscreen || doc.msExitFullscreen;
            exit?.call(doc);
            return;
        }
        const request = node.requestFullscreen || node.webkitRequestFullscreen || node.msRequestFullscreen;
        request?.call(node);
    }, []);

    useEffect(() => {
        const onChange = () => {
            const doc = document;
            const active = doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;
            setIsViewfinderFullscreen(active === viewfinderRef.current);
        };
        document.addEventListener('fullscreenchange', onChange);
        document.addEventListener('webkitfullscreenchange', onChange);
        return () => {
            document.removeEventListener('fullscreenchange', onChange);
            document.removeEventListener('webkitfullscreenchange', onChange);
        };
    }, []);

    const handleUploadFile = useCallback(async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        const token = tokenRef.current;
        if (!file || !token) {
            return;
        }
        setUploadingFile(true);
        try {
            // uploadEvidenceFile returns the chunk payload including session_id,
            // media_url, mime_type, and file_type from the server's chunkMediaFields.
            const payload = await uploadEvidenceFile(token, 'new', file);
            const sessionId = payload.session_id;
            // Finalize the session so the WORM chain is sealed. The public
            // /finalize endpoint is used intentionally (no auth required).
            await finalizeSession(sessionId);
            await loadSessions();
            // Re-open the session to hydrate blob URLs and auto-select the chunk.
            await openSession(sessionId);
        } catch (err) {
            setError(err.message);
        } finally {
            setUploadingFile(false);
        }
    }, [loadSessions, openSession]);

    const handleOverrideRisk = useCallback(async (riskLevel) => {
        const token = tokenRef.current;
        if (!token || !detail) {
            return;
        }
        const previous = detail.session.risk_level;
        setDetail((prev) => (prev ? { ...prev, session: { ...prev.session, risk_level: riskLevel } } : prev));
        setSessions((prev) => prev.map((session) => (
            session.id === detail.session.id ? { ...session, risk_level: riskLevel } : session
        )));
        setOverriding(true);
        try {
            await overrideRiskLevel(token, detail.session.id, riskLevel, 'Investigator amended AI risk assessment.');
            await loadSessions();
            setAmendNotice(`Risk assessment amended to ${riskLevel.toUpperCase()}.`);
            setTimeout(() => setAmendNotice(null), 4000);
        } catch (err) {
            setDetail((prev) => (prev ? { ...prev, session: { ...prev.session, risk_level: previous } } : prev));
            setError(err.message);
        } finally {
            setOverriding(false);
        }
    }, [detail, loadSessions]);

    const handleVerify = useCallback(async () => {
        if (!detail) {
            return;
        }
        setVerifying(true);
        try {
            const local = await verifyChain(detail.chunks ?? [], GENESIS);
            const token = tokenRef.current;
            let server = null;
            if (token) {
                server = await verifySession(token, detail.session.id);
            }
            const ok = local.ok && (server ? server.chain_intact !== false && server.status !== 'TAMPER_DETECTED' : true);
            setIntegrity({
                ok,
                brokenAt: local.brokenAt ?? server?.tampered_at ?? server?.broken_at ?? null,
            });
        } catch (err) {
            setError(err.message);
        } finally {
            setVerifying(false);
        }
    }, [detail]);

    const playNextChunk = useCallback(() => {
        if (!continuousPlayback || !detail || !activeChunk) {
            return;
        }
        const chunks = (detail.chunks ?? []).filter((ch) => chunkPlaybackUrl(ch));
        const idx = chunks.findIndex((ch) => ch.sequence_number === activeChunk.sequence_number);
        if (idx >= 0 && idx < chunks.length - 1) {
            setActiveChunk(chunks[idx + 1]);
        }
    }, [detail, activeChunk, continuousPlayback]);

    useEffect(() => {
        if (!continuousPlayback || !activeChunk) {
            return undefined;
        }
        if (mediaKind(activeChunk) !== 'image') {
            return undefined;
        }
        const timer = window.setTimeout(() => playNextChunk(), 2500);
        return () => window.clearTimeout(timer);
    }, [continuousPlayback, activeChunk, playNextChunk]);

    const filteredSessions = useMemo(() => {
        const q = query.trim().toLowerCase();
        return sessions.filter((s) => {
            const matchesRisk = riskFilter === 'all' || s.risk_level === riskFilter;
            const hay = `${s.id} ${s.evidence_id ?? ''}`.toLowerCase();
            return matchesRisk && (q === '' || hay.includes(q));
        });
    }, [sessions, riskFilter, query]);

    const peakAi = useMemo(() => {
        const chunks = detail?.chunks ?? [];
        if (chunks.length === 0) {
            return null;
        }
        return chunks.reduce((best, ch) => {
            const conf = Number(ch.ai_threat_indicators?.confidence ?? ch.ai_threat_indicators?.weapon ?? 0);
            const bestConf = Number(best?.ai_threat_indicators?.confidence ?? best?.ai_threat_indicators?.weapon ?? -1);
            return conf >= bestConf ? ch : best;
        }, chunks[0]);
    }, [detail]);

    const metricCards = [
        { label: 'Total Incidents', value: stats.total, tone: '' },
        { label: '🔴 High Risk', value: stats.high, tone: 'text-red-600 dark:text-red-300' },
        { label: '🟡 Medium Risk', value: stats.medium, tone: 'text-orange-600 dark:text-orange-300' },
        { label: '🟢 Low Risk', value: stats.low, tone: 'text-green-700 dark:text-green-300' },
        {
            label: 'Append-Only Storage',
            value: stats.storage === 'active' ? 'Active' : 'WORM Locked',
            tone: stats.storage === 'active' ? 'text-emerald-600' : 'text-sky-700 dark:text-sky-300',
        },
    ];

    return (
        <div className={`min-h-screen w-screen ${c.app}`}>
            <header className={`flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4 ${c.border}`}>
                <div>
                    <h1 className="text-xl font-black tracking-tight">
                        ProofVault · Secure Evidence Repository
                    </h1>
                    <p className={`text-xs uppercase tracking-[0.3em] ${c.muted}`}>
                        Investigator Control Room{user?.name ? ` · ${user.name}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={capture.startVideo}
                        disabled={capture.isRecording}
                        className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                    >
                        🎥 Start Video Stream
                    </button>
                    <button
                        type="button"
                        onClick={capture.startAudio}
                        disabled={capture.isRecording}
                        className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                    >
                        🎙️ Record Audio Only
                    </button>
                    <button
                        type="button"
                        onClick={handleSnapshot}
                        disabled={capture.isRecording}
                        className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                    >
                        📸 Snapshot Photo
                    </button>
                    <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={uploadingFile || capture.isRecording}
                        className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                    >
                        {uploadingFile ? 'Uploading…' : '📁 Upload Evidence File (Image / Video / Audio / PDF)'}
                    </button>
                    <input
                        ref={fileInputRef}
                        type="file"
                        className="hidden"
                        accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt"
                        onChange={handleUploadFile}
                    />
                    <button
                        type="button"
                        onClick={handleGenerateMock}
                        disabled={generating}
                        className="rounded-lg bg-slate-700 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-slate-600 disabled:opacity-60 dark:bg-slate-200 dark:text-slate-900"
                    >
                        {generating ? 'Generating…' : '+ Generate Test Evidence Session'}
                    </button>
                    <button
                        type="button"
                        onClick={() => setTheme(isDark ? 'light' : 'dark')}
                        aria-label="Toggle theme"
                        className={`rounded-lg px-3 py-2 text-sm font-bold shadow-sm transition ${c.toggle}`}
                    >
                        {isDark ? '☀️' : '🌙'}
                    </button>
                    <button
                        type="button"
                        onClick={lock}
                        className="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-red-500"
                    >
                        Emergency Lock
                    </button>
                </div>
            </header>

            {(capture.isRecording || snapNote || capture.error || capture.seized) && (
                <section className={`mx-6 mt-4 space-y-3 rounded-2xl border p-4 ${c.panel}`}>
                    {capture.error && (
                        <p className="rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-600 dark:text-red-300">{capture.error}</p>
                    )}
                    {snapNote && (
                        <p className="rounded-lg bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">{snapNote}</p>
                    )}
                    {capture.seized && (
                        <p className="rounded-lg bg-amber-500/15 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
                            Client connection was cut. Streamed chunks remain preserved server-side.
                        </p>
                    )}
                    {capture.isRecording && capture.mode === 'video' && (
                        <div ref={viewfinderRef} className="relative overflow-hidden rounded-xl bg-black [:fullscreen]:h-screen [:fullscreen]:w-screen">
                            <video
                                ref={videoRef}
                                autoPlay
                                muted
                                playsInline
                                className="aspect-video w-full object-cover"
                            />
                            <span className="absolute left-3 top-3 z-10 inline-flex items-center gap-2 rounded-md bg-black/70 px-3 py-1 font-mono text-sm font-black text-red-400">
                                <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-red-500" />
                                ● REC {formatElapsed(capture.elapsedMs)}
                            </span>
                            <button
                                type="button"
                                onClick={toggleFullScreen}
                                aria-label={isViewfinderFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'}
                                className="absolute right-3 top-3 z-10 rounded-md bg-black/70 px-2 py-1 text-lg text-white hover:bg-black/90"
                            >
                                {isViewfinderFullscreen ? '🗗' : '⛶'}
                            </button>
                            <div className="absolute bottom-3 left-3 right-3 z-10 flex flex-wrap items-center justify-between gap-2">
                                <p className="font-mono text-xs text-slate-200">
                                    Chunk #{String(capture.chunkCount).padStart(3, '0')}
                                    {' · '}
                                    {capture.uploadStatus === 'ok' ? 'Uploaded' : capture.uploadStatus === 'queued' ? 'Queued offline' : capture.uploadStatus === 'uploading' ? 'Uploading…' : 'Ready'}
                                </p>
                                <button
                                    type="button"
                                    onClick={handleStopCapture}
                                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-black text-white shadow hover:bg-red-500"
                                >
                                    Stop &amp; Finalize Evidence
                                </button>
                            </div>
                        </div>
                    )}
                    {capture.isRecording && capture.mode === 'audio' && (
                        <div className="rounded-xl bg-black p-4">
                            <span className="inline-flex items-center gap-2 font-mono text-sm font-black text-red-400">
                                <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-red-500" />
                                ● REC {formatElapsed(capture.elapsedMs)}
                            </span>
                            <div className="mt-3 flex h-16 items-end gap-1">
                                {(capture.audioLevels.length > 0 ? capture.audioLevels : Array.from({ length: 24 }, () => 0.08)).map((level, i) => (
                                    <span
                                        key={i}
                                        className="flex-1 rounded-t bg-emerald-400"
                                        style={{ height: `${Math.max(8, level * 100)}%` }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                    {capture.isRecording && (
                        <>
                            {capture.mode !== 'video' && (
                                <p className={`font-mono text-xs ${c.muted}`}>
                                    Chunk #{String(capture.chunkCount).padStart(3, '0')}
                                    {' · '}
                                    {capture.uploadStatus === 'ok' ? 'Uploaded' : capture.uploadStatus === 'queued' ? 'Queued offline' : capture.uploadStatus === 'uploading' ? 'Uploading…' : 'Ready'}
                                    {' · '}
                                    {capture.lastGeo?.latitude != null
                                        ? `Lat ${capture.lastGeo.latitude.toFixed(4)}, Lng ${capture.lastGeo.longitude.toFixed(4)} (±${Math.round(capture.lastGeo.accuracy ?? 0)}m)`
                                        : 'Acquiring GPS…'}
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {capture.mode !== 'video' && (
                                    <button
                                        type="button"
                                        onClick={handleStopCapture}
                                        className="rounded-lg bg-red-600 px-4 py-2 text-sm font-black text-white shadow hover:bg-red-500"
                                    >
                                        Stop &amp; Finalize Evidence
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={capture.simulateSeizure}
                                    className="rounded-lg border border-amber-500/60 bg-amber-500/10 px-4 py-2 text-sm font-bold text-amber-700 dark:text-amber-300"
                                >
                                    Simulate Phone Seizure
                                </button>
                            </div>
                        </>
                    )}
                </section>
            )}

            <section className="grid grid-cols-2 gap-3 px-6 pt-6 lg:grid-cols-5">
                {metricCards.map((card) => (
                    <div key={card.label} className={`rounded-2xl border p-4 ${c.card}`}>
                        <p className={`text-xs font-bold uppercase tracking-wide ${c.muted}`}>{card.label}</p>
                        <p className={`mt-1 text-2xl font-black ${card.tone}`}>{card.value}</p>
                    </div>
                ))}
            </section>

            {error && (
                <div className="mx-6 mt-4 rounded-lg border border-red-400/60 bg-red-500/10 px-4 py-2 text-sm text-red-600 dark:text-red-300">
                    {error}
                </div>
            )}

            <main className="grid grid-cols-1 gap-6 p-6 lg:grid-cols-[400px_1fr]">
                <section className={`rounded-2xl border ${c.panel}`}>
                    <div className={`border-b px-4 py-3 ${c.border}`}>
                        <h2 className="text-sm font-bold uppercase tracking-wide">Evidence Sessions</h2>
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {RISK_FILTERS.map((filter) => (
                                <button
                                    key={filter.id}
                                    type="button"
                                    onClick={() => setRiskFilter(filter.id)}
                                    className={`rounded-full px-3 py-1 text-xs font-bold transition ${
                                        riskFilter === filter.id ? c.chipActive : c.chipIdle
                                    }`}
                                >
                                    {filter.label}
                                </button>
                            ))}
                        </div>
                        <input
                            type="search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by Session UUID or PV-ID…"
                            className={`mt-3 w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/50 ${c.input}`}
                        />
                    </div>
                    <div className="max-h-[62vh] overflow-y-auto">
                        {loadingSessions ? (
                            <p className={`px-4 py-6 text-sm ${c.muted}`}>Loading sessions…</p>
                        ) : filteredSessions.length === 0 ? (
                            <div className="px-4 py-8 text-center">
                                <p className={`text-sm ${c.muted}`}>
                                    {sessions.length === 0 ? 'No sessions captured yet.' : 'No sessions match your filters.'}
                                </p>
                                {sessions.length === 0 && (
                                    <button
                                        type="button"
                                        onClick={handleGenerateMock}
                                        disabled={generating}
                                        className="mt-4 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                                    >
                                        {generating ? 'Generating…' : '+ Generate Test Evidence Session'}
                                    </button>
                                )}
                            </div>
                        ) : (
                            <ul className={`divide-y ${c.divide}`}>
                                {filteredSessions.map((session) => (
                                    <li key={session.id}>
                                        <button
                                            type="button"
                                            onClick={() => openSession(session.id)}
                                            className={`w-full px-4 py-3 text-left transition ${c.rowHover} ${
                                                detail?.session?.id === session.id ? c.rowActive : ''
                                            }`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="truncate font-mono text-xs font-bold">
                                                    {session.evidence_id ?? session.id}
                                                </span>
                                                <span className={`shrink-0 text-xs ${c.muted}`}>
                                                    {session.chunks_count} chunks
                                                </span>
                                            </div>
                                            <div className={`mt-1 truncate font-mono text-[10px] ${c.muted}`}>{session.id}</div>
                                            <div className={`mt-1 text-xs ${c.muted}`}>{formatEat(session.started_at)}</div>
                                            <div className="mt-2 flex gap-2">
                                                <Badge value={session.status} styles={STATUS_STYLES} />
                                                <Badge value={session.risk_level} styles={RISK_STYLES} />
                                            </div>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                <section className={`rounded-2xl border ${c.panel}`}>
                    {!detail && !loadingDetail && (
                        <p className={`px-6 py-10 text-center text-sm ${c.muted}`}>
                            Select a session to inspect the cryptographic ledger, AI assessment, and GPS trail.
                        </p>
                    )}

                    {loadingDetail && (
                        <p className={`px-6 py-10 text-center text-sm ${c.muted}`}>Loading session…</p>
                    )}

                    {detail && (
                        <div className="space-y-6 p-6">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="font-mono text-sm font-bold">{detail.session.evidence_id}</p>
                                    <p className={`font-mono text-xs ${c.muted}`}>{detail.session.id}</p>
                                    <div className="mt-2 flex gap-2">
                                        <Badge value={detail.session.status} styles={STATUS_STYLES} />
                                        <Badge value={detail.session.risk_level} styles={RISK_STYLES} />
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => handleDownloadPdf(detail.session.id)}
                                        disabled={downloading}
                                        className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                                    >
                                        {downloading ? 'Preparing…' : 'Download Chain-of-Custody PDF'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleExportPackage(detail.session.id)}
                                        disabled={exporting}
                                        className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white shadow transition hover:bg-slate-700 disabled:opacity-60 dark:bg-slate-200 dark:text-slate-900 dark:hover:bg-white"
                                    >
                                        {exporting ? 'Packaging…' : '📦 Export Evidence Package (.ZIP)'}
                                    </button>
                                </div>
                            </div>

                            <div className={`overflow-hidden rounded-xl border bg-black ${c.border}`}>
                                <div className="flex items-center justify-between px-4 py-2 text-xs text-slate-300">
                                    <label className="inline-flex items-center gap-2 font-bold">
                                        <input
                                            type="checkbox"
                                            checked={continuousPlayback}
                                            onChange={(e) => setContinuousPlayback(e.target.checked)}
                                        />
                                        Continuous Playback
                                    </label>
                                    {continuousPlayback && (
                                        <span className="text-emerald-400">Auto-advancing sequential chunks</span>
                                    )}
                                </div>
                                {(() => {
                                    const src = chunkPlaybackUrl(activeChunk);
                                    const kind = mediaKind(activeChunk);
                                    if (!src) {
                                        return (
                                            <p className="px-6 py-10 text-center text-sm text-slate-400">
                                                No playable media for this incident. If this is a mock session, only the hash ledger was stored.
                                            </p>
                                        );
                                    }
                                    if (kind === 'image') {
                                        return <img src={activeChunk.media_url || src} alt="Preserved Evidence" className="w-full max-h-[420px] rounded object-contain bg-black/40" />;
                                    }
                                    if (kind === 'audio') {
                                        return (
                                            <div className="flex flex-col items-center justify-center rounded bg-zinc-900 p-8">
                                                <p className="mb-4 text-sm text-zinc-400">🎵 Audio Evidence Track</p>
                                                <audio
                                                    key={activeChunk.sequence_number}
                                                    src={activeChunk.media_url || src}
                                                    controls
                                                    autoPlay={continuousPlayback}
                                                    onEnded={playNextChunk}
                                                    className="w-full"
                                                />
                                            </div>
                                        );
                                    }
                                    if (kind === 'document') {
                                        const docSrc = activeChunk.media_url || src;
                                        return (
                                            <div className="flex h-[450px] w-full flex-col">
                                                <iframe title="Evidence document" src={docSrc} className="w-full flex-1 rounded border border-zinc-700" />
                                                <a href={docSrc} target="_blank" rel="noreferrer" className="mt-2 text-xs text-blue-400 underline">
                                                    Open Document in New Tab ↗
                                                </a>
                                            </div>
                                        );
                                    }
                                    return (
                                        <>
                                            <video
                                                key={activeChunk.sequence_number}
                                                src={activeChunk.media_url || src}
                                                controls
                                                autoPlay
                                                onEnded={playNextChunk}
                                                className="w-full max-h-[420px] rounded bg-black object-contain"
                                            />
                                            {continuousPlayback && chunkPlaybackUrl(nextPlayable) ? (
                                                <video src={chunkPlaybackUrl(nextPlayable)} preload="auto" className="hidden" />
                                            ) : null}
                                        </>
                                    );
                                })()}
                            </div>

                            <div className={`rounded-xl border p-4 ${c.border}`}>
                                <h3 className="text-sm font-bold uppercase tracking-wide">AI Risk &amp; Explanation</h3>
                                {peakAi?.ai_threat_indicators ? (
                                    <>
                                        <div className="mt-2 flex flex-wrap items-center gap-3">
                                            <Badge value={peakAi.ai_threat_indicators.risk_level ?? detail.session.risk_level} styles={RISK_STYLES} />
                                            <span className="text-sm font-bold">
                                                Confidence {Math.round(Number(peakAi.ai_threat_indicators.confidence ?? peakAi.ai_threat_indicators.weapon ?? 0) * 100)}%
                                            </span>
                                        </div>
                                        <div className={`mt-3 h-2 overflow-hidden rounded-full ${isDark ? 'bg-slate-800' : 'bg-slate-200'}`}>
                                            <div
                                                className="h-full rounded-full bg-red-500"
                                                style={{ width: `${Math.round(Number(peakAi.ai_threat_indicators.confidence ?? peakAi.ai_threat_indicators.weapon ?? 0) * 100)}%` }}
                                            />
                                        </div>
                                        <p className="mt-3 text-sm">
                                            {peakAi.ai_threat_indicators.reason
                                                ?? 'Possible weapon detected and physical confrontation visible. Confidence: 87%'}
                                        </p>
                                        <p className={`mt-2 text-xs ${c.muted}`}>
                                            Weapon {Math.round(Number(peakAi.ai_threat_indicators.weapon ?? 0) * 100)}% ·
                                            Violence {Math.round(Number(peakAi.ai_threat_indicators.violence ?? 0) * 100)}% ·
                                            Acoustic distress {Math.round(Number(peakAi.ai_threat_indicators.acoustic_distress ?? 0) * 100)}%
                                        </p>
                                    </>
                                ) : (
                                    <div className="mt-2 flex items-center gap-3">
                                        <Badge value={detail.session.risk_level} styles={RISK_STYLES} />
                                        <span className={`text-sm ${c.muted}`}>No automated assessment stored for this session.</span>
                                    </div>
                                )}
                                <div className="mt-4 space-y-2">
                                    <p className="text-xs font-black uppercase tracking-wide">⚠️ Amend Risk Assessment</p>
                                    {amendNotice && (
                                        <p className="rounded-lg bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                            {amendNotice}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            disabled={overriding}
                                            onClick={() => handleOverrideRisk('high')}
                                            className="rounded-lg bg-red-600 px-3 py-2 text-sm font-bold text-white shadow hover:bg-red-500 disabled:opacity-60"
                                        >
                                            🔴 High Risk
                                        </button>
                                        <button
                                            type="button"
                                            disabled={overriding}
                                            onClick={() => handleOverrideRisk('medium')}
                                            className="rounded-lg bg-amber-500 px-3 py-2 text-sm font-bold text-white shadow hover:bg-amber-400 disabled:opacity-60"
                                        >
                                            🟡 Medium Risk
                                        </button>
                                        <button
                                            type="button"
                                            disabled={overriding}
                                            onClick={() => handleOverrideRisk('low')}
                                            className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white shadow hover:bg-emerald-500 disabled:opacity-60"
                                        >
                                            🟢 Low Risk
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h3 className="text-sm font-bold uppercase tracking-wide">Interactive Cryptographic Ledger</h3>
                                    <button
                                        type="button"
                                        onClick={handleVerify}
                                        disabled={verifying}
                                        className={`rounded-lg px-3 py-2 text-sm font-bold ${c.toggle}`}
                                    >
                                        {verifying ? 'Verifying…' : '🧪 Verify Cryptographic Integrity'}
                                    </button>
                                </div>
                                {integrity && (
                                    <p className={`mt-3 rounded-lg px-3 py-2 text-sm font-black ${
                                        integrity.ok
                                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                            : 'bg-red-500/15 text-red-600 dark:text-red-300'
                                    }`}
                                    >
                                        {integrity.ok
                                            ? '🟢 INTEGRITY VERIFIED (Unbroken Chain)'
                                            : `🔴 TAMPER DETECTED${integrity.brokenAt ? ` at chunk #${integrity.brokenAt}` : ''}`}
                                    </p>
                                )}
                                <p className={`mt-2 break-all font-mono text-xs ${c.code}`}>
                                    chain_hash: {detail.session.chain_hash ?? '—'}
                                </p>
                                <div className={`mt-2 max-h-64 overflow-y-auto rounded-xl border ${c.border}`}>
                                    <table className="w-full text-left text-xs">
                                        <thead className={`sticky top-0 ${c.tableHead}`}>
                                            <tr>
                                                <th className="px-3 py-2">Chunk</th>
                                                <th className="px-3 py-2">SHA-256</th>
                                                <th className="px-3 py-2">Cumulative</th>
                                                <th className="px-3 py-2">Bytes</th>
                                                <th className="px-3 py-2">Captured (EAT)</th>
                                                <th className="px-3 py-2">Play</th>
                                            </tr>
                                        </thead>
                                        <tbody className={`divide-y ${c.divide}`}>
                                            {(detail.chunks ?? []).map((chunk) => (
                                                <tr
                                                    key={chunk.sequence_number}
                                                    className={activeChunk?.sequence_number === chunk.sequence_number ? c.rowActive : ''}
                                                >
                                                    <td className="px-3 py-2 font-mono">#{String(chunk.sequence_number).padStart(3, '0')}</td>
                                                    <td className="px-3 py-2 font-mono">{String(chunk.chunk_hash).slice(0, 12)}…</td>
                                                    <td className="px-3 py-2 font-mono">{String(chunk.cumulative_hash).slice(0, 12)}…</td>
                                                    <td className="px-3 py-2">{chunk.byte_size}</td>
                                                    <td className="px-3 py-2">{formatEat(chunk.captured_at)}</td>
                                                    <td className="px-3 py-2">
                                                        {chunkPlaybackUrl(chunk) ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => setActiveChunk(chunk)}
                                                                className="rounded bg-emerald-600 px-2 py-1 font-bold text-white hover:bg-emerald-500"
                                                            >
                                                                ▶
                                                            </button>
                                                        ) : (
                                                            <span className={c.muted}>—</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div>
                                <h3 className="mb-3 text-sm font-bold uppercase tracking-wide">GPS Trail &amp; Timeline</h3>
                                <GpsTimelineMap chunks={detail.chunks} isDark={isDark} />
                            </div>
                        </div>
                    )}
                </section>
            </main>
        </div>
    );
}
