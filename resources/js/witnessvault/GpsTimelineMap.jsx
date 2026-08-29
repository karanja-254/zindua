import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

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

/**
 * Interactive OpenStreetMap trail for a session's GPS breadcrumbs.
 */
export default function GpsTimelineMap({ chunks, isDark }) {
    const containerRef = useRef(null);
    const mapRef = useRef(null);

    const points = (chunks ?? []).filter((ch) => ch.latitude != null && ch.longitude != null);
    const trailKey = points
        .map((p) => `${p.sequence_number}:${p.latitude}:${p.longitude}:${p.chunk_hash}`)
        .join('|');

    useEffect(() => {
        if (!containerRef.current || points.length === 0) {
            return undefined;
        }

        const map = L.map(containerRef.current, {
            scrollWheelZoom: true,
            attributionControl: true,
        });
        mapRef.current = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        const latlngs = points.map((p) => [Number(p.latitude), Number(p.longitude)]);
        const line = L.polyline(latlngs, {
            color: '#059669',
            weight: 4,
            opacity: 0.9,
        }).addTo(map);

        points.forEach((p) => {
            const latlng = [Number(p.latitude), Number(p.longitude)];
            const accuracy = p.accuracy_meters != null ? Number(p.accuracy_meters) : null;

            if (accuracy && accuracy > 0) {
                L.circle(latlng, {
                    radius: accuracy,
                    color: '#0ea5e9',
                    fillColor: '#38bdf8',
                    fillOpacity: 0.12,
                    weight: 1,
                }).addTo(map);
            }

            const marker = L.circleMarker(latlng, {
                radius: 8,
                color: '#064e3b',
                fillColor: '#10b981',
                fillOpacity: 1,
                weight: 2,
            }).addTo(map);

            marker.bindPopup(
                `<div style="font: 12px/1.4 ui-sans-serif,system-ui,sans-serif;min-width:220px">
                    <strong>Chunk #${String(p.sequence_number).padStart(3, '0')}</strong><br/>
                    ${formatEat(p.captured_at)}<br/>
                    Accuracy: ${accuracy != null ? `${accuracy} m` : 'n/a'}<br/>
                    <span style="font-family:ui-monospace,monospace;word-break:break-all">SHA-256: ${p.chunk_hash ?? '—'}</span>
                </div>`,
            );
        });

        if (latlngs.length === 1) {
            map.setView(latlngs[0], 16);
        } else {
            map.fitBounds(line.getBounds().pad(0.2));
        }

        const invalidate = () => map.invalidateSize();
        requestAnimationFrame(invalidate);
        window.addEventListener('resize', invalidate);

        return () => {
            window.removeEventListener('resize', invalidate);
            map.remove();
            mapRef.current = null;
        };
    }, [trailKey, isDark]);

    if (points.length === 0) {
        return <p className="text-sm text-slate-500">No GPS breadcrumbs recorded for this session.</p>;
    }

    return (
        <div
            ref={containerRef}
            className={`h-80 w-full overflow-hidden rounded-xl border ${isDark ? 'border-slate-800' : 'border-slate-200'}`}
        />
    );
}
