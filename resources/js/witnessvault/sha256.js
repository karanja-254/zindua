function toHex(buffer) {
    return Array.from(new Uint8Array(buffer))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * SHA-256 hex digest of a UTF-8 string (used for cryptographic chain verification).
 *
 * @param {string} value
 * @returns {Promise<string>}
 */
export async function sha256HexOfString(value) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
    return toHex(digest);
}

/**
 * SHA-256 hex digest of a binary Blob (used for snapshot integrity).
 *
 * @param {Blob} blob
 * @returns {Promise<string>}
 */
export async function sha256HexOfBlob(blob) {
    const buffer = await blob.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', buffer);
    return toHex(digest);
}

/**
 * Recompute the cumulative SHA-256 chain and verify it against stored values.
 *
 * @param {Array<{chunk_hash: string, cumulative_hash: string}>} chunks ordered by sequence
 * @param {string} [genesis]
 * @returns {Promise<{ok: boolean, brokenAt: number|null}>}
 */
export async function verifyChain(chunks, genesis = '0'.repeat(64)) {
    let previous = genesis;

    for (let i = 0; i < chunks.length; i += 1) {
        const expected = await sha256HexOfString(previous + chunks[i].chunk_hash);
        if (expected !== chunks[i].cumulative_hash) {
            return { ok: false, brokenAt: chunks[i].sequence_number ?? i + 1 };
        }
        previous = chunks[i].cumulative_hash;
    }

    return { ok: true, brokenAt: null };
}
