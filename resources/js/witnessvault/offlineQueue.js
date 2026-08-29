const DB_NAME = 'proofvault';
const STORE = 'chunk_queue';
const DB_VERSION = 1;

function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * Persist a chunk that could not be uploaded so it survives reloads/seizure.
 *
 * @param {{sessionId: string, blob: Blob, geo: object}} record
 * @returns {Promise<void>}
 */
export async function enqueueChunk(record) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).add({ ...record, queuedAt: Date.now() });
        tx.oncomplete = () => {
            db.close();
            resolve();
        };
        tx.onerror = () => {
            db.close();
            reject(tx.error);
        };
    });
}

/**
 * @returns {Promise<Array<{id: number, sessionId: string, blob: Blob, geo: object}>>}
 */
export async function getAllQueued() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const request = tx.objectStore(STORE).getAll();
        request.onsuccess = () => {
            db.close();
            resolve(request.result ?? []);
        };
        request.onerror = () => {
            db.close();
            reject(request.error);
        };
    });
}

/**
 * @param {number} id
 * @returns {Promise<void>}
 */
export async function deleteQueued(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).delete(id);
        tx.oncomplete = () => {
            db.close();
            resolve();
        };
        tx.onerror = () => {
            db.close();
            reject(tx.error);
        };
    });
}

/**
 * @returns {Promise<number>}
 */
export async function queuedCount() {
    const items = await getAllQueued();
    return items.length;
}
