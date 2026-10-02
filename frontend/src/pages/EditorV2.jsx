import { useState, useRef, useEffect, useMemo, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../services/api';
import ChatPanel from '../components/ChatPanel';
import '../editor-v2.css';

let segIdCounter = 0;
const genId = () => `seg_${++segIdCounter}`;

// ─── FreeCut-style icons (SVG) ──────────────────────
const Icon = ({ d, size = 16, stroke = 'currentColor', fill = 'none' }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill={fill} stroke={stroke} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">{typeof d === 'string' ? <path d={d} /> : d}</svg>
);

const Icons = {
    back: <Icon d={<><path d="M19 12H5" /><path d="M12 19l-7-7 7-7" /></>} />,
    save: <Icon d={<><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" /><polyline points="17 21 17 13 7 13 7 21" /><polyline points="7 3 7 8 15 8" /></>} />,
    undo: <Icon d={<><path d="M3 10h10a5 5 0 015 5v2" /><path d="M3 10l5-5M3 10l5 5" /></>} />,
    redo: <Icon d={<><path d="M21 10H11a5 5 0 00-5 5v2" /><path d="M21 10l-5-5M21 10l-5 5" /></>} />,
    scissors: <Icon d={<><circle cx="6" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><line x1="20" y1="4" x2="8.12" y2="15.88" /><line x1="14.47" y1="14.48" x2="20" y2="20" /><line x1="8.12" y1="8.12" x2="12" y2="12" /></>} />,
    trash: <Icon d={<><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6M14 11v6" /></>} />,
    play: <Icon d={<polygon points="5 3 19 12 5 21" />} fill="currentColor" stroke="none" />,
    pause: <Icon d={<><rect x="6" y="4" width="4" height="16" /><rect x="14" y="4" width="4" height="16" /></>} fill="currentColor" stroke="none" />,
    skipBack: <Icon d={<><polygon points="19 20 9 12 19 4" /><line x1="5" y1="19" x2="5" y2="5" /></>} size={14} />,
    skipFwd: <Icon d={<><polygon points="5 4 15 12 5 20" /><line x1="19" y1="5" x2="19" y2="19" /></>} size={14} />,
    zoomIn: <Icon d={<><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /><line x1="11" y1="8" x2="11" y2="14" /><line x1="8" y1="11" x2="14" y2="11" /></>} size={14} />,
    zoomOut: <Icon d={<><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /><line x1="8" y1="11" x2="14" y2="11" /></>} size={14} />,
    eye: <Icon d={<><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></>} size={13} />,
    lock: <Icon d={<><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0110 0v4" /></>} size={13} />,
    volume: <Icon d={<><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" /><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07" /></>} size={13} />,
    snap: <Icon d={<><path d="M21 12H3M12 3v18" /></>} size={14} />,
    camera: <Icon d={<><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z" /><circle cx="12" cy="13" r="4" /></>} size={14} />,
};

// ─── Timeline track config ──────────────────────
const TRACK_DEFS = [
    { key: 'video', icon: '🎬', label: 'V1', fullLabel: 'Video', color: '#3d7aed' },
    { key: 'broll', icon: '🎞️', label: 'V2', fullLabel: 'B-Roll', color: '#8b5cf6' },
    { key: 'audio', icon: '🔊', label: 'A1', fullLabel: 'Audio', color: '#7c3aed' },
    { key: 'subtitle', icon: '📝', label: 'T1', fullLabel: 'Altyazı', color: '#a3a3a3' },
];

const SNAP_THRESHOLD = 0.5;
const MIN_DURATION = 0.5;

export default function EditorV2() {
    const { projectId } = useParams();
    const navigate = useNavigate();
    const [project, setProject] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [currentTime, setCurrentTime] = useState(0);
    const [isPlaying, setIsPlaying] = useState(false);
    const [duration, setDuration] = useState(60);
    const [playbackSpeed, setPlaybackSpeed] = useState(1);
    const [selectedSegment, setSelectedSegment] = useState(null);
    const [segments, setSegments] = useState([]);
    const [undoStack, setUndoStack] = useState([]);
    const [redoStack, setRedoStack] = useState([]);
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [zoom, setZoom] = useState(1);
    const [dragState, setDragState] = useState(null);
    const [draggingPlayhead, setDraggingPlayhead] = useState(false);
    const [snapEnabled, setSnapEnabled] = useState(true);
    const videoRef = useRef(null);
    const brollVideoRef = useRef(null);
    const timelineBodyRef = useRef(null);
    const trackContentRef = useRef(null);

    const LABEL_WIDTH = 176;
    const pixelsPerSecond = 14 * zoom;
    const totalWidth = Math.max(duration * pixelsPerSecond, 800);

    // ─── Format time ──────────────────────
    const fmt = (s) => {
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = Math.floor(s % 60);
        const fr = Math.floor((s % 1) * 30);
        return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${sec.toString().padStart(2, '0')}:${fr.toString().padStart(2, '0')}`;
    };

    // ─── Load project ──────────────────────
    useEffect(() => { if (projectId) loadProject(); }, [projectId]);
    const loadProject = async () => {
        try { const data = await api.getProject(projectId); setProject(data); }
        catch { setLoadError('Layihə tapılmadı'); }
    };

    // Renew the short legacy media cookie while this editor is open.
    useEffect(() => {
        if (!project?.playback_url?.startsWith('/api/projects/') || api.usesSharedSignIn()) return;
        const timer = setInterval(() => api.prepareMediaSession(project.id).catch(() => {
            videoRef.current?.pause(); setLoadError('Video access has ended');
        }), 240000);
        return () => clearInterval(timer);
    }, [project?.id, project?.playback_url]);

    // ─── Build segments ──────────────────────
    useEffect(() => {
        if (!project) return;
        const segs = [];
        if (project.source_url) {
            segs.push({ id: genId(), type: 'video', start: 0, end: project.source_duration || duration, label: project.title || 'Main Video', locked: false });
        }
        project.clips?.forEach(clip => {
            clip.broll_items?.forEach(broll => {
                segs.push({ id: genId(), type: 'broll', start: broll.start ?? 0, end: broll.end ?? 6, label: broll.keyword || 'B-Roll', src: broll.src, thumbnail: broll.thumbnail, keyword: broll.keyword, reason: broll.reason, brollType: broll.type || 'video' });
            });
            clip.sound_effects?.forEach(sfx => {
                segs.push({ id: genId(), type: 'audio', start: clip.trim_start || 0, end: (clip.trim_start || 0) + (sfx.duration || 3), label: sfx.name || 'SFX' });
            });
        });
        setSegments(segs);
    }, [project, duration]);

    // ─── Video segment bounds ──────────────────────
    const videoSegment = useMemo(() => segments.find(s => s.type === 'video') || { start: 0, end: duration }, [segments, duration]);
    const videoOffset = videoSegment.start;
    const vidDur = videoSegment.end - videoSegment.start;

    // ─── Video sync ──────────────────────
    const handleTimeUpdate = () => {
        if (!videoRef.current) return;
        const it = videoRef.current.currentTime;
        const tt = it + videoOffset;
        if (tt >= videoSegment.end) { videoRef.current.pause(); videoRef.current.currentTime = vidDur; setCurrentTime(videoSegment.end); setIsPlaying(false); return; }
        setCurrentTime(tt);
    };
    const handleVideoLoaded = () => { if (videoRef.current) setDuration(videoRef.current.duration || 60); };
    const handleSeek = useCallback((time) => {
        setCurrentTime(time);
        if (videoRef.current) { const it = time - videoOffset; if (it >= 0 && it <= vidDur) videoRef.current.currentTime = it; }
    }, [videoOffset, vidDur]);
    const togglePlay = () => {
        if (!videoRef.current) return;
        if (isPlaying) { videoRef.current.pause(); } else {
            if (currentTime >= videoSegment.end - 0.1) { videoRef.current.currentTime = 0; setCurrentTime(videoSegment.start); }
            const it = currentTime - videoOffset;
            if (it >= 0 && it <= vidDur) { videoRef.current.currentTime = it; videoRef.current.play(); }
        }
        setIsPlaying(!isPlaying);
    };
    useEffect(() => { if (videoRef.current) videoRef.current.playbackRate = playbackSpeed; }, [playbackSpeed]);

    // ─── B-roll overlay ──────────────────────
    const brollSegments = useMemo(() => segments.filter(s => s.type === 'broll' && s.src), [segments]);
    const activeBroll = useMemo(() => brollSegments.find(s => currentTime >= s.start && currentTime < s.end) || null, [brollSegments, currentTime]);
    useEffect(() => {
        if (brollVideoRef.current) { if (isPlaying && activeBroll?.brollType === 'video') brollVideoRef.current.play().catch(() => { }); else brollVideoRef.current.pause(); }
    }, [isPlaying, activeBroll]);

    // ─── Undo/Redo ──────────────────────
    const pushUndo = useCallback(() => { setUndoStack(p => [...p.slice(-30), JSON.stringify(segments)]); setRedoStack([]); }, [segments]);
    const undo = useCallback(() => { setUndoStack(p => { if (!p.length) return p; setRedoStack(r => [...r, JSON.stringify(segments)]); setSegments(JSON.parse(p[p.length - 1])); return p.slice(0, -1); }); }, [segments]);
    const redo = useCallback(() => { setRedoStack(p => { if (!p.length) return p; setUndoStack(u => [...u, JSON.stringify(segments)]); setSegments(JSON.parse(p[p.length - 1])); return p.slice(0, -1); }); }, [segments]);

    // ─── Segment ops ──────────────────────
    const handleSegmentChange = useCallback((segId, updates) => { setSegments(p => p.map(s => s.id === segId ? { ...s, ...updates } : s)); setHasUnsavedChanges(true); }, []);
    const handleSegmentSelect = useCallback((seg) => { setSelectedSegment(seg); }, []);
    const handleSplit = useCallback(() => {
        if (!selectedSegment) return;
        const seg = segments.find(s => s.id === selectedSegment.id);
        if (!seg || currentTime <= seg.start || currentTime >= seg.end) return;
        pushUndo();
        setSegments(p => p.map(s => s.id === seg.id ? { ...s, end: currentTime } : s).concat({ ...seg, id: genId(), start: currentTime, label: seg.label + ' (2)' }));
        setHasUnsavedChanges(true);
    }, [selectedSegment, segments, currentTime, pushUndo]);
    const handleDelete = useCallback(() => {
        if (!selectedSegment) return;
        pushUndo();
        setSegments(p => p.filter(s => s.id !== selectedSegment.id));
        setSelectedSegment(null);
        setHasUnsavedChanges(true);
    }, [selectedSegment, pushUndo]);

    // ─── Save ──────────────────────
    const saveTimeline = useCallback(async () => {
        if (!project || isSaving) return;
        setIsSaving(true);
        try { await api.saveTimeline(project.id, segments.map(s => ({ id: s.id, type: s.type, start: s.start, end: s.end, label: s.label, keyword: s.keyword }))); setHasUnsavedChanges(false); }
        catch (err) { console.error('Save failed:', err); }
        finally { setIsSaving(false); }
    }, [project, segments, isSaving]);

    useEffect(() => {
        const h = (e) => { if (hasUnsavedChanges) { e.preventDefault(); e.returnValue = ''; } };
        window.addEventListener('beforeunload', h); return () => window.removeEventListener('beforeunload', h);
    }, [hasUnsavedChanges]);

    // ─── Keyboard shortcuts ──────────────────────
    useEffect(() => {
        const handler = (e) => {
            if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;
            switch (e.key) {
                case ' ': e.preventDefault(); togglePlay(); break;
                case 'ArrowLeft': e.preventDefault(); handleSeek(Math.max(0, currentTime - 1 / 30)); break;
                case 'ArrowRight': e.preventDefault(); handleSeek(Math.min(duration, currentTime + 1 / 30)); break;
                case 'Delete': case 'Backspace': if (selectedSegment) { e.preventDefault(); handleDelete(); } break;
                case 's': case 'S': e.preventDefault(); if (e.ctrlKey || e.metaKey) saveTimeline(); else handleSplit(); break;
                case 'z': if (e.ctrlKey || e.metaKey) { e.preventDefault(); e.shiftKey ? redo() : undo(); } break;
            }
        };
        window.addEventListener('keydown', handler); return () => window.removeEventListener('keydown', handler);
    }, [currentTime, duration, selectedSegment]);

    // ─── Timeline: xToTime ──────────────────────
    const xToTime = useCallback((clientX) => {
        if (!trackContentRef.current) return 0;
        const rect = trackContentRef.current.getBoundingClientRect();
        const scrollLeft = timelineBodyRef.current?.scrollLeft || 0;
        return Math.max(0, Math.min(duration, (clientX - rect.left + scrollLeft) / pixelsPerSecond));
    }, [duration, pixelsPerSecond]);

    // ─── Timeline: snap ──────────────────────
    const snapTime = useCallback((time, excludeId) => {
        if (!snapEnabled) return time;
        const edges = [0, duration];
        segments.forEach(s => { if (s.id !== excludeId) { edges.push(s.start, s.end); } });
        for (const e of edges) { if (Math.abs(time - e) < SNAP_THRESHOLD) return e; }
        return time;
    }, [segments, duration, snapEnabled]);

    // ─── Timeline: drag/resize ──────────────────────
    const handleSegMouseDown = (e, seg, mode) => {
        e.stopPropagation(); e.preventDefault();
        handleSegmentSelect(seg);
        setDragState({ segId: seg.id, mode, startX: e.clientX, origStart: seg.start, origEnd: seg.end });
    };

    useEffect(() => {
        if (!dragState) return;
        const move = (e) => {
            const dt = (e.clientX - dragState.startX) / pixelsPerSecond;
            let ns, ne;
            if (dragState.mode === 'move') {
                const d = dragState.origEnd - dragState.origStart;
                ns = Math.max(0, dragState.origStart + dt); ne = ns + d;
                if (ne > duration) { ne = duration; ns = ne - d; }
                ns = snapTime(ns, dragState.segId); ne = ns + d;
            } else if (dragState.mode === 'resize-left') {
                ns = Math.max(0, snapTime(dragState.origStart + dt, dragState.segId));
                if (ns >= dragState.origEnd - MIN_DURATION) ns = dragState.origEnd - MIN_DURATION;
                ne = dragState.origEnd;
            } else {
                ne = Math.min(duration, snapTime(dragState.origEnd + dt, dragState.segId));
                if (ne <= dragState.origStart + MIN_DURATION) ne = dragState.origStart + MIN_DURATION;
                ns = dragState.origStart;
            }
            handleSegmentChange(dragState.segId, { start: ns, end: ne });
        };
        const up = () => setDragState(null);
        document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
        return () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
    }, [dragState, pixelsPerSecond, duration, snapTime, handleSegmentChange]);

    // ─── Timeline: playhead drag ──────────────────────
    useEffect(() => {
        if (!draggingPlayhead) return;
        const move = (e) => handleSeek(xToTime(e.clientX));
        const up = () => setDraggingPlayhead(false);
        document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
        return () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
    }, [draggingPlayhead, xToTime, handleSeek]);

    // ─── Grouped segments ──────────────────────
    const grouped = useMemo(() => {
        const g = {}; TRACK_DEFS.forEach(t => g[t.key] = []);
        segments.forEach(s => { if (g[s.type]) g[s.type].push(s); });
        return g;
    }, [segments]);

    // ─── Ticks ──────────────────────
    const tickInterval = zoom >= 3 ? 2 : zoom >= 2 ? 5 : zoom >= 1 ? 10 : 30;
    const ticks = []; for (let t = 0; t <= duration; t += tickInterval) ticks.push(t);

    // ─── Handle project update from AI ──────────────────────
    const handleProjectUpdate = (p) => setProject(p);

    if (loadError) return (
        <div className="v2-error"><h2>❌ {loadError}</h2><button onClick={() => navigate('/dashboard')}>← Dashboard</button></div>
    );

    return (
        <div className="v2-editor">
            {/* ═══ TOP TOOLBAR ═══ */}
            <div className="v2-toolbar">
                <div className="v2-toolbar-left">
                    <button className="v2-tb-btn" onClick={() => navigate('/dashboard')}>{Icons.back}</button>
                    <div className="v2-tb-sep" />
                    <div className="v2-project-info">
                        <span className="v2-project-name">{project?.title || 'Untitled'}</span>
                        <span className="v2-project-meta">{project?.source_resolution || '1080x1920'} | 30fps</span>
                    </div>
                </div>
                <div className="v2-toolbar-right">
                    <button className={`v2-tb-btn ${hasUnsavedChanges ? 'unsaved' : ''}`} onClick={saveTimeline} disabled={!hasUnsavedChanges || isSaving}>
                        {isSaving ? <span className="save-spinner" /> : Icons.save}
                        <span>Save</span>
                    </button>
                </div>
            </div>

            {/* ═══ MAIN CONTENT ═══ */}
            <div className="v2-main">
                {/* ─── PREVIEW AREA ─── */}
                <div className="v2-preview-area">
                    <div className="v2-preview-container">
                        {project?.source_url ? (
                            <div className="v2-canvas">
                                <video ref={videoRef} src={project.playback_url} onTimeUpdate={handleTimeUpdate} onLoadedMetadata={handleVideoLoaded} onPlay={() => setIsPlaying(true)} onPause={() => setIsPlaying(false)} style={{ opacity: activeBroll ? 0.3 : 1 }} />
                                {activeBroll && (
                                    <div className="v2-broll-overlay">
                                        {activeBroll.brollType === 'video' ? <video ref={brollVideoRef} src={activeBroll.src} muted autoPlay loop playsInline /> : <img src={activeBroll.src} alt="" />}
                                        <div className="v2-broll-badge">🎞️ {activeBroll.keyword}</div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="v2-preview-empty"><span>🎬</span><p>Video yüklənir...</p></div>
                        )}
                    </div>

                    {/* ─── Transport controls ─── */}
                    <div className="v2-transport">
                        <div className="v2-transport-left">
                            <span className="v2-timecode">{fmt(currentTime)}</span>
                            <span className="v2-timecode-sep">/</span>
                            <span className="v2-timecode dim">{fmt(duration)}</span>
                        </div>
                        <div className="v2-transport-center">
                            <button className="v2-tb-btn" onClick={() => handleSeek(0)}>{Icons.skipBack}</button>
                            <button className="v2-tb-btn" onClick={() => handleSeek(Math.max(0, currentTime - 1 / 30))}>{Icons.skipBack}</button>
                            <button className="v2-play-btn" onClick={togglePlay}>{isPlaying ? Icons.pause : Icons.play}</button>
                            <button className="v2-tb-btn" onClick={() => handleSeek(Math.min(duration, currentTime + 1 / 30))}>{Icons.skipFwd}</button>
                            <button className="v2-tb-btn" onClick={() => handleSeek(duration)}>{Icons.skipFwd}</button>
                            {Icons.camera}
                            {Icons.snap}
                        </div>
                        <div className="v2-transport-right">
                            <select className="v2-speed-select" value={playbackSpeed} onChange={e => setPlaybackSpeed(parseFloat(e.target.value))}>
                                <option value="0.5">0.5x</option><option value="1">1x</option><option value="1.5">1.5x</option><option value="2">2x</option>
                            </select>
                        </div>
                    </div>
                </div>

                {/* ─── AI CHAT ─── */}
                <ChatPanel project={project} onProjectUpdate={handleProjectUpdate} selectedSegment={selectedSegment} onClearSelection={() => setSelectedSegment(null)} />
            </div>

            {/* ═══ TIMELINE ═══ */}
            <div className="v2-timeline">
                {/* Timeline toolbar */}
                <div className="v2-tl-toolbar">
                    <span className="v2-tl-label">⏱ TIMELINE</span>
                    <div className="v2-tl-tools">
                        <button className="v2-tl-btn" title="Selection (V)" style={{ color: 'var(--v2-accent)' }}>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M4 2l14 10-6 2 4 8-3 1-4-8-5 4z" /></svg>
                        </button>
                        <button className="v2-tl-btn" onClick={handleSplit} title="Kəs (S)" disabled={!selectedSegment}>{Icons.scissors}</button>
                        <div className="v2-tl-sep" />
                        <button className="v2-tl-btn" onClick={undo} title="Undo (Ctrl+Z)" disabled={!undoStack.length}>{Icons.undo}</button>
                        <button className="v2-tl-btn" onClick={redo} title="Redo (Ctrl+Shift+Z)" disabled={!redoStack.length}>{Icons.redo}</button>
                        <div className="v2-tl-sep" />
                        <button className={`v2-tl-btn ${snapEnabled ? 'active' : ''}`} onClick={() => setSnapEnabled(p => !p)} title="Snap">{Icons.snap}</button>
                        <button className="v2-tl-btn" onClick={handleDelete} title="Sil (Del)" disabled={!selectedSegment}>{Icons.trash}</button>
                    </div>
                    <div className="v2-tl-zoom">
                        <button className="v2-tl-btn" onClick={() => setZoom(z => Math.max(0.25, z / 1.5))}>{Icons.zoomOut}</button>
                        <input type="range" className="v2-zoom-slider" min="0.25" max="5" step="0.05" value={zoom} onChange={e => setZoom(parseFloat(e.target.value))} />
                        <button className="v2-tl-btn" onClick={() => setZoom(z => Math.min(5, z * 1.5))}>{Icons.zoomIn}</button>
                    </div>
                </div>

                {/* Timeline body */}
                <div className="v2-tl-body" ref={timelineBodyRef}>
                    {/* Ruler */}
                    <div className="v2-tl-ruler" style={{ paddingLeft: LABEL_WIDTH }}>
                        <div style={{ width: totalWidth, position: 'relative', height: '100%' }}>
                            {ticks.map(t => (
                                <div key={t} className="v2-tl-tick" style={{ left: t * pixelsPerSecond }}>
                                    <span>{fmt(t).replace(/^\d+:/, '')}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Tracks */}
                    <div className="v2-tl-tracks" ref={trackContentRef}>
                        {TRACK_DEFS.map(track => (
                            <div key={track.key} className="v2-tl-track">
                                {/* Track header */}
                                <div className="v2-track-header">
                                    <span className="v2-track-label" style={{ '--track-color': track.color }}>
                                        {track.icon} {track.label}
                                    </span>
                                    <span className="v2-track-info">{grouped[track.key].length} Clip</span>
                                    <div className="v2-track-controls">
                                        <button className="v2-track-ctrl" title="Mute">{Icons.volume}</button>
                                        <button className="v2-track-ctrl" title="Visible">{Icons.eye}</button>
                                        <button className="v2-track-ctrl" title="Lock">{Icons.lock}</button>
                                    </div>
                                </div>
                                {/* Track content */}
                                <div className="v2-track-content" style={{ width: totalWidth }} onClick={(e) => { if (!dragState) handleSeek(xToTime(e.clientX)); }}>
                                    {grouped[track.key].map(seg => {
                                        const isSelected = seg.id === selectedSegment?.id;
                                        const isDragging = dragState?.segId === seg.id;
                                        return (
                                            <div key={seg.id}
                                                className={`v2-segment ${isSelected ? 'selected' : ''} ${isDragging ? 'dragging' : ''}`}
                                                style={{ left: seg.start * pixelsPerSecond, width: Math.max((seg.end - seg.start) * pixelsPerSecond, 24), '--seg-color': track.color }}
                                                onMouseDown={e => handleSegMouseDown(e, seg, 'move')}
                                            >
                                                <div className="v2-seg-handle left" onMouseDown={e => handleSegMouseDown(e, seg, 'resize-left')} />
                                                <div className="v2-seg-body">
                                                    <span className="v2-seg-icon">{seg.type === 'video' ? '⏺' : ''}</span>
                                                    <span className="v2-seg-label">{seg.label}</span>
                                                </div>
                                                <div className="v2-seg-handle right" onMouseDown={e => handleSegMouseDown(e, seg, 'resize-right')} />
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}

                        {/* Playhead */}
                        <div className="v2-playhead" style={{ left: currentTime * pixelsPerSecond + LABEL_WIDTH }} onMouseDown={(e) => { e.stopPropagation(); setDraggingPlayhead(true); }}>
                            <div className="v2-ph-handle" />
                            <div className="v2-ph-line" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
