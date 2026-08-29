const API_BASE = '/api/v1/evidence';
const AUTH_BASE = '/api/v1/auth';

/**
 * Authenticate with the single Master Keycode and return the auth payload.
 *
 * @param {string} keycode
 * @returns {Promise<{token_type: string, access_token: string, user: object}>}
 */
export async function registerVault(name, keycode) {
    const response = await fetch(`${AUTH_BASE}/register`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ name, keycode }),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new Error(payload.error ?? 'Registration failed');
    }

    return response.json();
}

/**
 * Authenticate with the single Master Keycode and return the auth payload.
 *
 * @param {string} keycode
 * @returns {Promise<{token_type: string, access_token: string, user: object}>}
 */
export async function unlockVault(keycode) {
    const response = await fetch(`${AUTH_BASE}/unlock`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ keycode }),
    });

    if (!response.ok) {
        throw new Error('Invalid Master Keycode');
    }

    return response.json();
}

/**
 * Open a new evidence session and return its server-minted UUID.
 *
 * @returns {Promise<string>} the session id
 */
export async function startSession() {
    const response = await fetch(`${API_BASE}/session/start`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to start session (${response.status})`);
    }

    const payload = await response.json();

    return payload.session_id;
}

/**
 * Open a new evidence session as an authenticated investigator (unthrottled).
 *
 * @param {string} token
 * @returns {Promise<string>}
 */
export async function startAuthenticatedSession(token) {
    const response = await fetch(`${API_BASE}/session`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to start session (${response.status})`);
    }

    const payload = await response.json();

    return payload.session_id;
}

/**
 * Stream a single raw media chunk to the WORM ingest endpoint.
 *
 * The blob is sent as the raw request body (never wrapped in FormData) so the
 * backend can pipe php://input straight into the hashing service. Geolocation is
 * attached via headers to match the Laravel EvidenceChunkController.
 *
 * @param {string} sessionId
 * @param {Blob} blob raw media bytes from MediaRecorder
 * @param {{latitude: number|null, longitude: number|null, accuracy: number|null, capturedAt: string}} geoData
 * @returns {Promise<Response>}
 */
export async function uploadChunk(sessionId, blob, geoData) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': blob?.type || 'application/octet-stream',
        'X-Captured-At': geoData?.capturedAt ?? new Date().toISOString(),
    };

    const ext = extensionFromMime(blob?.type);
    if (ext) {
        headers['X-Chunk-Ext'] = ext;
    }

    if (geoData?.latitude != null) {
        headers['X-Geo-Lat'] = String(geoData.latitude);
    }

    if (geoData?.longitude != null) {
        headers['X-Geo-Lng'] = String(geoData.longitude);
    }

    if (geoData?.accuracy != null) {
        headers['X-Geo-Accuracy'] = String(geoData.accuracy);
    }

    const response = await fetch(`${API_BASE}/${sessionId}/chunk`, {
        method: 'POST',
        headers,
        body: blob,
        keepalive: true,
    });

    if (!response.ok) {
        throw new Error(`Chunk upload rejected (${response.status})`);
    }

    return response;
}

/**
 * Seal the session so no further chunks can be appended.
 *
 * @param {string} sessionId
 * @returns {Promise<Response>}
 */
export async function finalizeSession(sessionId) {
    return fetch(`${API_BASE}/${sessionId}/finalize`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
        },
        keepalive: true,
    });
}

/**
 * Generate a mock finalized evidence session for testing the review flow.
 *
 * @param {string} token Sanctum bearer token
 * @returns {Promise<object>}
 */
export async function generateMockSession(token) {
    const response = await fetch(`${API_BASE}/mock`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to generate test session (${response.status})`);
    }

    return response.json();
}

/**
 * Fetch a paginated list of evidence sessions (authenticated).
 *
 * @param {string} token Sanctum bearer token
 * @returns {Promise<object>} Laravel paginator payload
 */
export async function listSessions(token) {
    const response = await fetch(`${API_BASE}`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to load sessions (${response.status})`);
    }

    return response.json();
}

/**
 * Fetch full session metadata + chunks with short-lived signed URLs.
 *
 * @param {string} token Sanctum bearer token
 * @param {string} sessionId
 * @returns {Promise<object>}
 */
export async function getSessionDetail(token, sessionId) {
    const response = await fetch(`${API_BASE}/${sessionId}`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to load session (${response.status})`);
    }

    return response.json();
}

/**
 * Independently verify the SHA-256 cumulative chain for a session.
 *
 * @param {string} token Sanctum bearer token
 * @param {string} sessionId
 * @returns {Promise<{ok: boolean, broken_at: number|null, evidence_id: string, chain_hash: string, ledger: object[]}>}
 */
export async function verifySession(token, sessionId) {
    const response = await fetch(`${API_BASE}/${sessionId}/verify`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (response.status === 422) {
        return payload;
    }

    if (!response.ok) {
        throw new Error(`Failed to verify chain (${response.status})`);
    }

    return payload;
}

/**
 * Download the full evidence ZIP package (PDF + ledger + hashes).
 *
 * @param {string} token Sanctum bearer token
 * @param {string} sessionId
 * @returns {Promise<void>}
 */
export async function downloadEvidencePackage(token, sessionId) {
    const response = await fetch(`${API_BASE}/${sessionId}/export-package`, {
        headers: {
            Accept: 'application/zip',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        throw new Error(`Failed to export package (${response.status})`);
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    const disposition = response.headers.get('Content-Disposition') ?? '';
    const match = disposition.match(/filename="?([^"]+)"?/i);
    anchor.href = url;
    anchor.download = match?.[1] ?? `proofvault-${sessionId.slice(0, 8)}.zip`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

/**
 * Download the forensic chain-of-custody PDF and trigger a browser save.
 *
 * @param {string} token Sanctum bearer token
 * @param {string} sessionId
 * @returns {Promise<void>}
 */
export async function downloadReport(token, sessionId) {
    const response = await fetch(`${API_BASE}/${sessionId}/report`, {
        method: 'GET',
        headers: {
            Accept: 'application/pdf',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        const message = payload.error ?? payload.message ?? `Failed to generate report (${response.status})`;
        throw new Error(message);
    }

    const blob = await response.blob();
    if (!blob || blob.size === 0) {
        throw new Error('Forensic PDF was empty.');
    }

    const objectUrl = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    const disposition = response.headers.get('Content-Disposition') ?? '';
    const match = disposition.match(/filename="?([^"]+)"?/i);
    anchor.href = objectUrl;
    anchor.download = match?.[1] ?? `proofvault-forensic-${sessionId}.pdf`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.URL.revokeObjectURL(objectUrl);
}

/**
 * Open an authenticated media stream for a chunk storage path proxied by the API.
 * Prefers the signed URL from the session detail payload when available.
 *
 * @param {string} signedUrl
 * @returns {string}
 */
export function resolveChunkSource(signedUrl) {
    return signedUrl ?? '';
}

function extensionFromMime(type) {
    const mime = String(type ?? '').toLowerCase();
    if (mime.includes('webm')) return 'webm';
    if (mime.includes('mp4')) return 'mp4';
    if (mime.includes('quicktime')) return 'mov';
    if (mime.includes('ogg')) return 'ogg';
    if (mime.includes('wav')) return 'wav';
    if (mime.includes('mpeg') || mime.includes('mp3')) return 'mp3';
    if (mime.includes('jpeg')) return 'jpg';
    if (mime.includes('png')) return 'png';
    if (mime.includes('webp')) return 'webp';
    if (mime.includes('pdf')) return 'pdf';
    return '';
}

/**
 * Upload a standalone evidence file (image, video, audio, PDF) into a session.
 *
 * @param {string} token
 * @param {string} sessionId
 * @param {File} file
 */
export async function uploadEvidenceFile(token, sessionId, file) {
    const body = new FormData();
    body.append('file', file);

    const response = await fetch(`${API_BASE}/${sessionId}/upload-file`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
        body,
    });

    if (!response.ok) {
        throw new Error(`File upload rejected (${response.status})`);
    }

    return response.json();
}

/**
 * Manually override the session AI risk tier.
 *
 * @param {string} token
 * @param {string} sessionId
 * @param {'high'|'medium'|'low'} riskLevel
 * @param {string} [reason]
 */
export async function overrideRiskLevel(token, sessionId, riskLevel, reason) {
    const response = await fetch(`${API_BASE}/${sessionId}/override-risk`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
            risk_level: riskLevel,
            override_reason: reason ?? 'Investigator manually overrode AI assessment.',
            reason: reason ?? 'Investigator manually overrode AI assessment.',
        }),
    });

    if (!response.ok) {
        throw new Error(`Risk override rejected (${response.status})`);
    }

    return response.json();
}

/**
 * Fetch binary media with the investigator bearer token and return a blob URL.
 *
 * @param {string} token
 * @param {string} url
 * @returns {Promise<string|null>}
 */
export async function fetchAuthorizedMediaUrl(token, url) {
    const response = await fetch(url, {
        headers: {
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        return null;
    }

    const blob = await response.blob();
    return URL.createObjectURL(blob);
}
