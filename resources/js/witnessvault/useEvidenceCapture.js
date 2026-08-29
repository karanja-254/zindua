import { useCallback, useEffect, useRef, useState } from 'react';
import { startSession, uploadChunk, finalizeSession } from './api';
import { enqueueChunk, getAllQueued, deleteQueued, queuedCount } from './offlineQueue';
import { sha256HexOfBlob } from './sha256';

const TIMESLICE_MS = 3000;
const MAX_BACKOFF_MS = 30_000;

/**
 * Resolve a single geolocation fix, degrading gracefully to nulls on failure.
 */
function readGeo(last) {
    const capturedAt = new Date().toISOString();
    return new Promise((resolve) => {
        if (!('geolocation' in navigator)) {
            resolve({ latitude: last?.latitude ?? null, longitude: last?.longitude ?? null, accuracy: last?.accuracy ?? null, capturedAt });
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve({
                latitude: pos.coords.latitude,
                longitude: pos.coords.longitude,
                accuracy: pos.coords.accuracy,
                capturedAt: new Date(pos.timestamp).toISOString(),
            }),
            () => resolve({ latitude: last?.latitude ?? null, longitude: last?.longitude ?? null, accuracy: last?.accuracy ?? null, capturedAt }),
            { enableHighAccuracy: true, timeout: 2500, maximumAge: 0 },
        );
    });
}

export function useEvidenceCapture() {
    const [mode, setMode] = useState(null); // 'video' | 'audio' | null
    const [isRecording, setIsRecording] = useState(false);
    const [elapsedMs, setElapsedMs] = useState(0);
    const [chunkCount, setChunkCount] = useState(0);
    const [uploadStatus, setUploadStatus] = useState('idle'); // idle | uploading | queued | ok
    const [lastGeo, setLastGeo] = useState(null);
    const [queued, setQueued] = useState(0);
    const [seized, setSeized] = useState(false);
    const [error, setError] = useState(null);
    const [previewStream, setPreviewStream] = useState(null);
    const [audioLevels, setAudioLevels] = useState([]);
    const [flushing, setFlushing] = useState(false);

    const streamRef = useRef(null);
    const recorderRef = useRef(null);
    const sessionIdRef = useRef(null);
    const geoWatchRef = useRef(null);
    const timerRef = useRef(null);
    const startedAtRef = useRef(0);
    const lastGeoRef = useRef(null);
    const drainingRef = useRef(false);
    const audioCtxRef = useRef(null);
    const analyserRafRef = useRef(null);

    const refreshQueued = useCallback(async () => {
        try {
            setQueued(await queuedCount());
        } catch {
            /* ignore */
        }
    }, []);

    // Upload a chunk, transparently buffering to IndexedDB when offline/failed.
    const dispatchChunk = useCallback(async (sessionId, blob, geo) => {
        if (!navigator.onLine) {
            await enqueueChunk({ sessionId, blob, geo });
            setUploadStatus('queued');
            await refreshQueued();
            return;
        }
        setUploadStatus('uploading');
        try {
            await uploadChunk(sessionId, blob, geo);
            setUploadStatus('ok');
        } catch {
            await enqueueChunk({ sessionId, blob, geo });
            setUploadStatus('queued');
            await refreshQueued();
        }
    }, [refreshQueued]);

    // Drain the offline queue with exponential backoff until empty or offline.
    const drainQueue = useCallback(async () => {
        if (drainingRef.current || !navigator.onLine) {
            return;
        }
        drainingRef.current = true;
        setFlushing(true);
        let backoff = 1000;

        try {
            let items = await getAllQueued();
            while (items.length > 0 && navigator.onLine) {
                const item = items[0];
                try {
                    setUploadStatus('uploading');
                    await uploadChunk(item.sessionId, item.blob, item.geo);
                    await deleteQueued(item.id);
                    setUploadStatus('ok');
                    backoff = 1000;
                } catch {
                    await new Promise((r) => setTimeout(r, backoff));
                    backoff = Math.min(backoff * 2, MAX_BACKOFF_MS);
                    if (backoff >= MAX_BACKOFF_MS) {
                        break;
                    }
                }
                items = await getAllQueued();
            }
        } finally {
            drainingRef.current = false;
            setFlushing(false);
            await refreshQueued();
        }
    }, [refreshQueued]);

    useEffect(() => {
        refreshQueued();
        const onOnline = () => drainQueue();
        window.addEventListener('online', onOnline);
        return () => window.removeEventListener('online', onOnline);
    }, [drainQueue, refreshQueued]);

    // Flush immediately when recording starts (or resumes) while the browser is online.
    useEffect(() => {
        if (isRecording && navigator.onLine) {
            drainQueue();
        }
    }, [isRecording, drainQueue]);

    const stopAudioMeter = useCallback(() => {
        if (analyserRafRef.current) {
            cancelAnimationFrame(analyserRafRef.current);
            analyserRafRef.current = null;
        }
        if (audioCtxRef.current) {
            audioCtxRef.current.close().catch(() => {});
            audioCtxRef.current = null;
        }
        setAudioLevels([]);
    }, []);

    const startAudioMeter = useCallback((stream) => {
        stopAudioMeter();
        try {
            const ctx = new AudioContext();
            const source = ctx.createMediaStreamSource(stream);
            const analyser = ctx.createAnalyser();
            analyser.fftSize = 64;
            analyser.smoothingTimeConstant = 0.7;
            source.connect(analyser);
            audioCtxRef.current = ctx;

            const data = new Uint8Array(analyser.frequencyBinCount);
            const tick = () => {
                analyser.getByteFrequencyData(data);
                const bars = Array.from({ length: 24 }, (_, i) => {
                    const idx = Math.min(data.length - 1, Math.floor((i / 24) * data.length));
                    return data[idx] / 255;
                });
                setAudioLevels(bars);
                analyserRafRef.current = requestAnimationFrame(tick);
            };
            tick();
        } catch {
            /* analyser unavailable — HUD still works */
        }
    }, [stopAudioMeter]);

    const startTelemetry = useCallback(() => {
        startedAtRef.current = Date.now();
        setElapsedMs(0);
        timerRef.current = setInterval(() => {
            setElapsedMs(Date.now() - startedAtRef.current);
        }, 250);
        geoWatchRef.current = navigator.geolocation?.watchPosition(
            (pos) => {
                const geo = {
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                    capturedAt: new Date(pos.timestamp).toISOString(),
                };
                lastGeoRef.current = geo;
                setLastGeo(geo);
            },
            () => {},
            { enableHighAccuracy: true, maximumAge: 2000 },
        );
    }, []);

    const teardownMedia = useCallback(() => {
        if (timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }
        if (geoWatchRef.current != null && navigator.geolocation) {
            navigator.geolocation.clearWatch(geoWatchRef.current);
            geoWatchRef.current = null;
        }

        const recorder = recorderRef.current;
        recorderRef.current = null;
        if (recorder) {
            recorder.ondataavailable = null;
            recorder.onerror = null;
            try {
                if (recorder.state !== 'inactive') {
                    recorder.stop();
                }
            } catch {
                /* already stopped */
            }
        }

        const stream = streamRef.current;
        streamRef.current = null;
        if (stream) {
            stream.getTracks().forEach((track) => {
                try {
                    track.stop();
                } catch {
                    /* ignore */
                }
            });
        }

        setPreviewStream(null);
        stopAudioMeter();
    }, [stopAudioMeter]);

    const beginRecorder = useCallback(async (constraints, captureMode) => {
        setError(null);
        setSeized(false);
        try {
            const sessionId = await startSession();
            sessionIdRef.current = sessionId;

            const stream = await navigator.mediaDevices.getUserMedia(constraints);
            streamRef.current = stream;
            setPreviewStream(stream);

            const recorder = new MediaRecorder(stream);
            recorderRef.current = recorder;
            recorder.ondataavailable = async (event) => {
                if (!event.data || event.data.size === 0 || !sessionIdRef.current) {
                    return;
                }
                const geo = await readGeo(lastGeoRef.current);
                lastGeoRef.current = geo;
                setLastGeo(geo);
                setChunkCount((n) => n + 1);
                await dispatchChunk(sessionIdRef.current, event.data, geo);
            };

            recorder.start(TIMESLICE_MS);
            setMode(captureMode);
            setIsRecording(true);
            setChunkCount(0);
            startTelemetry();

            if (captureMode === 'audio') {
                startAudioMeter(stream);
            }
        } catch (err) {
            setError(err?.message ?? 'Capture failed to start.');
            teardownMedia();
            sessionIdRef.current = null;
        }
    }, [dispatchChunk, startAudioMeter, startTelemetry, teardownMedia]);

    const startVideo = useCallback(() => beginRecorder(
        { video: { facingMode: 'environment' }, audio: true },
        'video',
    ), [beginRecorder]);

    const startAudio = useCallback(() => beginRecorder(
        { audio: true },
        'audio',
    ), [beginRecorder]);

    // Single high-resolution snapshot: hashed and dispatched as its own session.
    const snapshotPhoto = useCallback(async () => {
        setError(null);
        let stream = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } },
            });
            const video = document.createElement('video');
            video.srcObject = stream;
            video.muted = true;
            await video.play();
            await new Promise((r) => setTimeout(r, 350));

            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 1920;
            canvas.height = video.videoHeight || 1080;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.95));

            if (!blob) {
                throw new Error('Snapshot capture produced no image.');
            }

            const hash = await sha256HexOfBlob(blob);
            const geo = await readGeo(lastGeoRef.current);
            const activeSession = sessionIdRef.current ?? (await startSession());

            setChunkCount((n) => n + 1);
            await dispatchChunk(activeSession, blob, geo);

            if (!sessionIdRef.current) {
                await finalizeSession(activeSession);
            }

            return { hash, geo };
        } catch (err) {
            setError(err?.message ?? 'Snapshot failed.');
            return null;
        } finally {
            if (stream) {
                stream.getTracks().forEach((track) => {
                    try {
                        track.stop();
                    } catch {
                        /* ignore */
                    }
                });
            }
        }
    }, [dispatchChunk]);

    const stopAndFinalize = useCallback(async () => {
        const sessionId = sessionIdRef.current;
        teardownMedia();
        setIsRecording(false);
        setMode(null);
        if (sessionId) {
            try {
                await finalizeSession(sessionId);
            } catch {
                /* server keeps partial stream regardless */
            }
        }
        sessionIdRef.current = null;
    }, [teardownMedia]);

    // Abruptly cut the client without finalizing — proves server-side preservation.
    const simulateSeizure = useCallback(() => {
        teardownMedia();
        setIsRecording(false);
        setMode(null);
        setSeized(true);
        sessionIdRef.current = null; // intentionally never call finalize
    }, [teardownMedia]);

    useEffect(() => {
        const hardStop = () => teardownMedia();
        window.addEventListener('pagehide', hardStop);
        window.addEventListener('beforeunload', hardStop);
        return () => {
            window.removeEventListener('pagehide', hardStop);
            window.removeEventListener('beforeunload', hardStop);
            teardownMedia();
        };
    }, [teardownMedia]);

    return {
        mode,
        isRecording,
        elapsedMs,
        chunkCount,
        uploadStatus,
        lastGeo,
        queued,
        seized,
        error,
        previewStream,
        audioLevels,
        flushing,
        startVideo,
        startAudio,
        snapshotPhoto,
        stopAndFinalize,
        simulateSeizure,
        releaseHardware: teardownMedia,
        flushQueue: drainQueue,
    };
}
