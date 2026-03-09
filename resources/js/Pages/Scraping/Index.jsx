import AppLayout from '@/Layouts/AppLayout';
import { router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

// ── Status helpers ───────────────────────────────────────────────────────────

const STATUS_STYLES = {
    pending:   { color: '#ffd166', bg: 'rgba(255,209,102,0.08)', border: 'rgba(255,209,102,0.2)', dot: '#ffd166' },
    running:   { color: '#60a5fa', bg: 'rgba(96,165,250,0.08)',  border: 'rgba(96,165,250,0.2)',  dot: '#60a5fa' },
    completed: { color: '#00e5a0', bg: 'rgba(0,229,160,0.08)',   border: 'rgba(0,229,160,0.2)',   dot: '#00e5a0' },
    failed:    { color: '#ff6b6b', bg: 'rgba(255,107,107,0.08)', border: 'rgba(255,107,107,0.2)', dot: '#ff6b6b' },
};

function StatusBadge({ status }) {
    const s = STATUS_STYLES[status] || STATUS_STYLES.pending;
    return (
        <span style={{
            display: 'inline-flex', alignItems: 'center', gap: 5,
            padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 600,
            background: s.bg, border: `1px solid ${s.border}`, color: s.color,
        }}>
            <span style={{
                width: 6, height: 6, borderRadius: '50%', background: s.dot,
                animation: status === 'running' ? 'pulse 1.5s infinite' : status === 'pending' ? 'pulse 2s infinite' : 'none',
            }} />
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    );
}

function ProgressBar({ percent, status }) {
    const color = status === 'failed' ? '#ff6b6b' : status === 'completed' ? '#00e5a0' : '#60a5fa';
    return (
        <div style={{ marginTop: 8 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#6b6b80', marginBottom: 4 }}>
                <span>Progress</span><span>{percent}%</span>
            </div>
            <div style={{ background: '#1a1a28', borderRadius: 100, height: 6, overflow: 'hidden' }}>
                <div style={{
                    height: '100%', borderRadius: 100,
                    width: `${percent}%`,
                    background: status === 'running'
                        ? `linear-gradient(90deg, ${color}, ${color}88)`
                        : color,
                    transition: 'width 0.5s ease',
                    boxShadow: status === 'running' ? `0 0 8px ${color}60` : 'none',
                }} />
            </div>
        </div>
    );
}

function JobCard({ job, onCancel }) {
    return (
        <div style={{
            background: '#101018', border: '1px solid #1e1e2e', borderRadius: 14,
            padding: 20, transition: 'border-color 0.2s',
        }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
                <div>
                    <div style={{ color: '#fff', fontWeight: 700, fontSize: 14 }}>{job.website_name}</div>
                    <div style={{ color: '#3d3d52', fontSize: 11, marginTop: 2, fontFamily: 'monospace' }}>
                        {job.website_url}
                    </div>
                </div>
                <StatusBadge status={job.status} />
            </div>

            {/* Stats row */}
            <div style={{ display: 'flex', gap: 20, marginBottom: 12 }}>
                <Stat label="Products" value={job.scraped_products.toLocaleString()} />
                {job.platform_detected && <Stat label="Platform" value={job.platform_detected} />}
                {job.started_at && <Stat label="Started" value={job.started_at} />}
                {job.completed_at && <Stat label="Finished" value={job.completed_at} />}
            </div>

            {/* Progress bar (running or completed) */}
            {['running', 'completed'].includes(job.status) && (
                <ProgressBar percent={job.progress_percent} status={job.status} />
            )}

            {/* Error */}
            {job.error_message && (
                <div style={{ marginTop: 10, padding: '8px 12px', background: 'rgba(255,107,107,0.06)', border: '1px solid rgba(255,107,107,0.15)', borderRadius: 7, color: '#ff6b6b', fontSize: 11, fontFamily: 'monospace' }}>
                    {job.error_message}
                </div>
            )}

            {/* Actions */}
            <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                {job.status === 'completed' && job.download_url && (
                    <a href={job.download_url} style={{
                        padding: '8px 18px', borderRadius: 8, textDecoration: 'none',
                        background: 'linear-gradient(135deg,#00e5a0,#00b87a)', color: '#000',
                        fontWeight: 700, fontSize: 12, display: 'flex', alignItems: 'center', gap: 6,
                    }}>
                        ↓ Download CSV
                    </a>
                )}
                {['pending', 'running'].includes(job.status) && (
                    <button onClick={() => onCancel(job.id)} style={{
                        padding: '8px 16px', borderRadius: 8, border: '1px solid rgba(255,107,107,0.2)',
                        background: 'rgba(255,107,107,0.06)', color: '#ff6b6b', fontSize: 12, cursor: 'pointer',
                    }}>
                        Cancel
                    </button>
                )}
            </div>
        </div>
    );
}

const Stat = ({ label, value }) => (
    <div>
        <div style={{ fontSize: 10, color: '#3d3d52', textTransform: 'uppercase', letterSpacing: '0.06em' }}>{label}</div>
        <div style={{ fontSize: 13, color: '#9090a8', marginTop: 1, fontWeight: 500 }}>{value || '—'}</div>
    </div>
);

// ── Main page ────────────────────────────────────────────────────────────────

export default function ScrapingIndex({ websites, jobs: initialJobs }) {
    const [selected, setSelected] = useState([]);
    const [liveJobs, setLiveJobs] = useState([]);
    const [historyJobs, setHistoryJobs] = useState(initialJobs?.data || []);
    const [polling, setPolling]   = useState(false);

    const startForm = useForm({ website_ids: [] });

    // Toggle website selection
    const toggle = (id) => {
        setSelected(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const selectAll = () => setSelected(websites.map(w => w.id));
    const clearAll  = () => setSelected([]);

    // Start scraping
    const handleStart = () => {
        if (!selected.length) return;
        startForm.setData('website_ids', selected);
        startForm.post('/scraping/start', {
            onSuccess: () => {
                setSelected([]);
                setPolling(true);
            }
        });
    };

    // Poll for live job updates every 3 seconds
    const fetchLive = useCallback(async () => {
        try {
            const res  = await fetch('/scraping/poll');
            const data = await res.json();
            setLiveJobs(data);

            // Stop polling when no more active jobs
            const hasActive = data.some(j => ['pending', 'running'].includes(j.status));
            if (!hasActive && data.length === 0) setPolling(false);
        } catch {}
    }, []);

    useEffect(() => {
        if (!polling) return;
        fetchLive();
        const interval = setInterval(fetchLive, 3000);
        return () => clearInterval(interval);
    }, [polling, fetchLive]);

    // Start polling if there are active jobs on mount
    useEffect(() => {
        const hasActive = initialJobs?.data?.some(j => ['pending','running'].includes(j.status));
        if (hasActive) {
            setPolling(true);
        }
    }, []);

    const handleCancel = (jobId) => {
        router.post(`/scraping/${jobId}/cancel`);
    };

    // Merge live jobs with history (live takes priority)
    const allDisplayJobs = [
        ...liveJobs,
        ...historyJobs.filter(hj => !liveJobs.find(lj => lj.id === hj.id))
    ].slice(0, 20);

    return (
        <AppLayout title="Scraping">
            <style>{`
                @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }
                @keyframes spin  { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
            `}</style>

            <div style={{ display: 'grid', gridTemplateColumns: '360px 1fr', gap: 24, alignItems: 'start' }}>

                {/* ── Left Panel: Site Selector ──────────────────────── */}
                <div style={{ background: '#101018', border: '1px solid #1e1e2e', borderRadius: 14, overflow: 'hidden', position: 'sticky', top: 80 }}>
                    <div style={{ padding: '18px 20px', borderBottom: '1px solid #1e1e2e', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <div>
                            <div style={{ color: '#fff', fontWeight: 700, fontSize: 14 }}>Select Websites</div>
                            <div style={{ color: '#3d3d52', fontSize: 11, marginTop: 2 }}>{selected.length} of {websites.length} selected</div>
                        </div>
                        <div style={{ display: 'flex', gap: 6 }}>
                            <button onClick={selectAll} style={smallBtn}>All</button>
                            <button onClick={clearAll}  style={smallBtn}>Clear</button>
                        </div>
                    </div>

                    {/* Website list */}
                    <div style={{ maxHeight: 380, overflowY: 'auto' }}>
                        {websites.length === 0 ? (
                            <div style={{ padding: '40px 20px', textAlign: 'center', color: '#3d3d52', fontSize: 13 }}>
                                No websites yet.{' '}
                                <a href="/websites" style={{ color: '#00e5a0', textDecoration: 'none' }}>Add one →</a>
                            </div>
                        ) : websites.map(w => {
                            const isSelected = selected.includes(w.id);
                            return (
                                <div key={w.id} onClick={() => toggle(w.id)} style={{
                                    display: 'flex', alignItems: 'center', gap: 12,
                                    padding: '13px 20px', cursor: 'pointer',
                                    background: isSelected ? 'rgba(0,229,160,0.05)' : 'transparent',
                                    borderBottom: '1px solid #1a1a28',
                                    transition: 'background 0.1s',
                                    borderLeft: isSelected ? '3px solid #00e5a0' : '3px solid transparent',
                                }}>
                                    {/* Checkbox */}
                                    <div style={{
                                        width: 18, height: 18, borderRadius: 5, flexShrink: 0,
                                        border: `2px solid ${isSelected ? '#00e5a0' : '#2a2a3a'}`,
                                        background: isSelected ? '#00e5a0' : 'transparent',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        {isSelected && <span style={{ color: '#000', fontSize: 11, fontWeight: 900 }}>✓</span>}
                                    </div>
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div style={{ color: isSelected ? '#fff' : '#9090a8', fontWeight: isSelected ? 600 : 400, fontSize: 13, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                            {w.name}
                                        </div>
                                        <div style={{ color: '#3d3d52', fontSize: 11, fontFamily: 'monospace', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                            {w.url.replace(/^https?:\/\//, '')}
                                        </div>
                                    </div>
                                    {w.platform && (
                                        <span style={{ fontSize: 10, color: '#6b6b80', background: '#1a1a28', padding: '2px 6px', borderRadius: 4, flexShrink: 0 }}>
                                            {w.platform}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Start button */}
                    <div style={{ padding: '16px 20px', borderTop: '1px solid #1e1e2e' }}>
                        <button
                            onClick={handleStart}
                            disabled={selected.length === 0 || startForm.processing}
                            style={{
                                width: '100%', padding: '13px 0', borderRadius: 10, border: 'none',
                                cursor: selected.length === 0 ? 'not-allowed' : 'pointer',
                                background: selected.length === 0
                                    ? '#1a1a28'
                                    : startForm.processing
                                        ? '#1a2e26'
                                        : 'linear-gradient(135deg,#00e5a0,#00b87a)',
                                color: selected.length === 0 ? '#3d3d52' : startForm.processing ? '#00e5a0' : '#000',
                                fontWeight: 800, fontSize: 14,
                                display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8,
                                transition: 'all 0.2s',
                            }}
                        >
                            {startForm.processing ? (
                                <>
                                    <span style={{ display: 'inline-block', animation: 'spin 1s linear infinite' }}>⟳</span>
                                    Queuing...
                                </>
                            ) : (
                                <>⟳ Start Scraping {selected.length > 0 ? `(${selected.length})` : ''}</>
                            )}
                        </button>
                        {selected.length > 0 && (
                            <div style={{ textAlign: 'center', marginTop: 8, fontSize: 11, color: '#3d3d52' }}>
                                Jobs run in background via queue
                            </div>
                        )}
                    </div>
                </div>

                {/* ── Right Panel: Job Status ────────────────────────── */}
                <div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
                        <div style={{ color: '#fff', fontWeight: 700, fontSize: 15 }}>
                            Job Status
                        </div>
                        {polling && (
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#60a5fa' }}>
                                <span style={{ display: 'inline-block', animation: 'spin 1.5s linear infinite' }}>⟳</span>
                                Live updates
                            </div>
                        )}
                    </div>

                    {allDisplayJobs.length === 0 ? (
                        <div style={{
                            border: '1px dashed #2a2a3a', borderRadius: 14,
                            padding: '60px 32px', textAlign: 'center', color: '#3d3d52',
                        }}>
                            <div style={{ fontSize: 32, marginBottom: 12 }}>⟳</div>
                            <div style={{ fontSize: 14 }}>No scrape jobs yet</div>
                            <div style={{ fontSize: 12, marginTop: 4 }}>Select websites and click "Start Scraping"</div>
                        </div>
                    ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            {allDisplayJobs.map(job => (
                                <JobCard key={job.id} job={job} onCancel={handleCancel} />
                            ))}
                        </div>
                    )}

                    {/* History table */}
                    {historyJobs.length > 0 && (
                        <div style={{ marginTop: 32 }}>
                            <div style={{ color: '#6b6b80', fontSize: 12, letterSpacing: '0.08em', textTransform: 'uppercase', marginBottom: 12 }}>
                                All History
                            </div>
                            <div style={{ background: '#101018', border: '1px solid #1e1e2e', borderRadius: 14, overflow: 'hidden' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ borderBottom: '1px solid #1e1e2e' }}>
                                            {['Website', 'Status', 'Products', 'Platform', 'Date', ''].map(h => (
                                                <th key={h} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 10, color: '#3d3d52', letterSpacing: '0.08em', textTransform: 'uppercase' }}>{h}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {historyJobs.map((j, i) => (
                                            <tr key={j.id} style={{ borderBottom: i < historyJobs.length-1 ? '1px solid #1a1a28' : 'none' }}>
                                                <td style={{ padding: '11px 16px', color: '#e8e8f0', fontSize: 13 }}>{j.website?.name}</td>
                                                <td style={{ padding: '11px 16px' }}><StatusBadge status={j.status} /></td>
                                                <td style={{ padding: '11px 16px', color: '#9090a8', fontSize: 13 }}>{j.scraped_products?.toLocaleString()}</td>
                                                <td style={{ padding: '11px 16px', color: '#6b6b80', fontSize: 12 }}>{j.platform_detected || '—'}</td>
                                                <td style={{ padding: '11px 16px', color: '#3d3d52', fontSize: 11 }}>{new Date(j.created_at).toLocaleDateString()}</td>
                                                <td style={{ padding: '11px 16px' }}>
                                                    {j.status === 'completed' && j.output_filename && (
                                                        <a href={`/scraping/${j.id}/download`} style={{
                                                            fontSize: 11, color: '#00e5a0', textDecoration: 'none',
                                                            padding: '4px 10px', borderRadius: 5,
                                                            background: 'rgba(0,229,160,0.08)', border: '1px solid rgba(0,229,160,0.15)',
                                                        }}>↓ CSV</a>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

const smallBtn = {
    padding: '4px 10px', borderRadius: 5, border: '1px solid #2a2a3a',
    background: '#1a1a28', color: '#6b6b80', fontSize: 11, cursor: 'pointer',
};