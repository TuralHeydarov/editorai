import { useState, useRef, useEffect, useMemo, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../services/api';
import Timeline from '../components/Timeline';
import ChatPanel from '../components/ChatPanel';

let segIdCounter = 0;
const genId = () => `seg_${++segIdCounter}`;

export default function Editor() {
  const { projectId } = useParams();
  const navigate = useNavigate();
  const [project, setProject] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [currentTime, setCurrentTime] = useState(0);
  const [isPlaying, setIsPlaying] = useState(false);
  const [duration, setDuration] = useState(60);
  const [playbackSpeed, setPlaybackSpeed] = useState(1);
  const [aspectRatio, setAspectRatio] = useState('9:16');
  const [selectedSegment, setSelectedSegment] = useState(null);
  const [segments, setSegments] = useState([]);
  const [undoStack, setUndoStack] = useState([]);
  const [redoStack, setRedoStack] = useState([]);
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const videoRef = useRef(null);
  const brollVideoRef = useRef(null);
  const initialSegmentsRef = useRef(null);

  // Aspect ratio presets
  const ASPECT_RATIOS = {
    '16:9': { w: 1920, h: 1080, label: '16:9' },
    '9:16': { w: 1080, h: 1920, label: '9:16' },
    '1:1': { w: 1080, h: 1080, label: '1:1' },
    '4:5': { w: 1080, h: 1350, label: '4:5' },
  };

  // ─── Load project ──────────────────────
  useEffect(() => {
    if (projectId) loadProject();
  }, [projectId]);

  const loadProject = async () => {
    try {
      const data = await api.getProject(projectId);
      setProject(data);
      // Auto-detect aspect from source_resolution
      if (data.source_resolution) {
        const [w, h] = data.source_resolution.split('x').map(Number);
        if (h > w) setAspectRatio('9:16');
        else if (w > h) setAspectRatio('16:9');
        else setAspectRatio('1:1');
      }
    } catch (err) {
      setLoadError('Layihə tapılmadı');
    }
  };

    // Renew the short legacy media cookie while this editor is open.
    useEffect(() => {
        if (!project?.playback_url?.startsWith('/api/projects/') || api.usesSharedSignIn()) return;
        const timer = setInterval(() => api.prepareMediaSession(project.id).catch(() => {
            videoRef.current?.pause(); setLoadError('Video access has ended');
        }), 240000);
        return () => clearInterval(timer);
    }, [project?.id, project?.playback_url]);

  // ─── Build segments from project data ──────────────────────
  useEffect(() => {
    if (!project) return;
    const segs = [];

    // Main video
    if (project.source_url) {
      segs.push({
        id: genId(),
        type: 'video',
        start: 0,
        end: project.source_duration || duration,
        label: project.title || 'Main Video',
        locked: true,
      });
    }

    // B-roll items from clips
    project.clips?.forEach(clip => {
      clip.broll_items?.forEach(broll => {
        segs.push({
          id: genId(),
          type: 'broll',
          start: broll.start ?? 0,
          end: broll.end ?? 6,
          label: broll.keyword || 'B-Roll',
          src: broll.src,
          thumbnail: broll.thumbnail,
          keyword: broll.keyword,
          reason: broll.reason,
          brollType: broll.type || 'video',
        });
      });
      // Sound effects
      clip.sound_effects?.forEach(sfx => {
        segs.push({
          id: genId(),
          type: 'audio',
          start: clip.trim_start || 0,
          end: (clip.trim_start || 0) + (sfx.duration || 3),
          label: sfx.name || 'SFX',
        });
      });
    });

    setSegments(segs);
  }, [project, duration]);

  // ─── Get main video segment bounds ──────────────────────
  const videoSegment = useMemo(() =>
    segments.find(s => s.type === 'video') || { start: 0, end: duration },
    [segments, duration]
  );

  // Video internal time = timeline position - segment start
  const videoOffset = videoSegment.start;
  const videoDuration = videoSegment.end - videoSegment.start;

  // ─── Video sync (offset-based for dragged segments) ──────────────────────
  const handleTimeUpdate = () => {
    if (!videoRef.current) return;
    const internalTime = videoRef.current.currentTime;
    const timelineTime = internalTime + videoOffset;

    // Stop at segment end
    if (timelineTime >= videoSegment.end) {
      videoRef.current.pause();
      videoRef.current.currentTime = videoDuration;
      setCurrentTime(videoSegment.end);
      setIsPlaying(false);
      return;
    }
    setCurrentTime(timelineTime);
  };

  const handleVideoLoaded = () => {
    if (videoRef.current) setDuration(videoRef.current.duration || 60);
  };

  const handleSeek = (time) => {
    if (videoRef.current) {
      setCurrentTime(time);
      // Map timeline position to internal video time
      const internalTime = time - videoOffset;
      if (internalTime >= 0 && internalTime <= videoDuration) {
        videoRef.current.currentTime = internalTime;
      }
    }
  };

  const togglePlay = () => {
    if (!videoRef.current) return;
    if (isPlaying) {
      videoRef.current.pause();
    } else {
      // If at end, restart from segment start
      if (currentTime >= videoSegment.end - 0.1) {
        videoRef.current.currentTime = 0;
        setCurrentTime(videoSegment.start);
      }
      // Only play if within segment bounds
      const internalTime = currentTime - videoOffset;
      if (internalTime >= 0 && internalTime <= videoDuration) {
        videoRef.current.currentTime = internalTime;
        videoRef.current.play();
      }
    }
    setIsPlaying(!isPlaying);
  };

  // Apply playback speed
  useEffect(() => {
    if (videoRef.current) videoRef.current.playbackRate = playbackSpeed;
  }, [playbackSpeed]);

  const formatTime = (s) => {
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    const ms = Math.floor((s % 1) * 100);
    return `${m}:${sec.toString().padStart(2, '0')}.${ms.toString().padStart(2, '0')}`;
  };

  // ─── B-roll overlay logic ──────────────────────
  const brollSegments = useMemo(() =>
    segments.filter(s => s.type === 'broll' && s.src),
    [segments]
  );

  const activeBroll = useMemo(() =>
    brollSegments.find(s => currentTime >= s.start && currentTime < s.end) || null,
    [brollSegments, currentTime]
  );

  useEffect(() => {
    if (brollVideoRef.current) {
      if (isPlaying && activeBroll?.brollType === 'video') {
        brollVideoRef.current.play().catch(() => { });
      } else {
        brollVideoRef.current.pause();
      }
    }
  }, [isPlaying, activeBroll]);

  // ─── Undo/Redo ──────────────────────
  const pushUndo = useCallback(() => {
    setUndoStack(prev => [...prev.slice(-30), JSON.stringify(segments)]);
    setRedoStack([]);
  }, [segments]);

  const undo = useCallback(() => {
    setUndoStack(prev => {
      if (prev.length === 0) return prev;
      const last = prev[prev.length - 1];
      setRedoStack(r => [...r, JSON.stringify(segments)]);
      setSegments(JSON.parse(last));
      return prev.slice(0, -1);
    });
  }, [segments]);

  const redo = useCallback(() => {
    setRedoStack(prev => {
      if (prev.length === 0) return prev;
      const last = prev[prev.length - 1];
      setUndoStack(u => [...u, JSON.stringify(segments)]);
      setSegments(JSON.parse(last));
      return prev.slice(0, -1);
    });
  }, [segments]);

  // ─── Segment operations ──────────────────────
  const handleSegmentChange = useCallback((segId, updates) => {
    setSegments(prev => prev.map(s => s.id === segId ? { ...s, ...updates } : s));
    setHasUnsavedChanges(true);
  }, []);

  const handleSegmentSelect = useCallback((seg) => {
    setSelectedSegment(seg);
  }, []);

  const handleSplit = useCallback(() => {
    if (!selectedSegment) return;
    const seg = segments.find(s => s.id === selectedSegment.id);
    if (!seg || seg.locked) return;
    if (currentTime <= seg.start || currentTime >= seg.end) return;

    pushUndo();
    const newSeg1 = { ...seg, end: currentTime };
    const newSeg2 = { ...seg, id: genId(), start: currentTime, label: seg.label + ' (2)' };
    setSegments(prev => prev.map(s => s.id === seg.id ? newSeg1 : s).concat(newSeg2));
  }, [selectedSegment, segments, currentTime, pushUndo]);

  const handleSegmentDelete = useCallback(() => {
    if (!selectedSegment || selectedSegment.locked) return;
    pushUndo();
    setSegments(prev => prev.filter(s => s.id !== selectedSegment.id));
    setSelectedSegment(null);
    setHasUnsavedChanges(true);
  }, [selectedSegment, pushUndo]);

  // ─── Save timeline ──────────────────────
  const saveTimeline = useCallback(async () => {
    if (!project || isSaving) return;
    setIsSaving(true);
    try {
      await api.saveTimeline(project.id, segments.map(s => ({
        id: s.id,
        type: s.type,
        start: s.start,
        end: s.end,
        label: s.label,
        keyword: s.keyword,
      })));
      setHasUnsavedChanges(false);
    } catch (err) {
      console.error('Save failed:', err);
    } finally {
      setIsSaving(false);
    }
  }, [project, segments, isSaving]);

  // ─── Unsaved changes warning ──────────────────────
  useEffect(() => {
    const handler = (e) => {
      if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [hasUnsavedChanges]);

  // ─── Keyboard shortcuts ──────────────────────
  useEffect(() => {
    const handler = (e) => {
      // Don't capture when typing in input/textarea
      if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;

      switch (e.key) {
        case ' ':
          e.preventDefault();
          togglePlay();
          break;
        case 'ArrowLeft':
          e.preventDefault();
          handleSeek(Math.max(0, currentTime - (1 / 30)));
          break;
        case 'ArrowRight':
          e.preventDefault();
          handleSeek(Math.min(duration, currentTime + (1 / 30)));
          break;
        case 'Delete':
        case 'Backspace':
          if (selectedSegment && !selectedSegment.locked) {
            e.preventDefault();
            handleSegmentDelete();
          }
          break;
        case 's':
        case 'S':
          if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            saveTimeline();
          } else {
            e.preventDefault();
            handleSplit();
          }
          break;
        case 'z':
          if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            if (e.shiftKey) redo();
            else undo();
          }
          break;
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [currentTime, duration, selectedSegment]);

  // ─── Project updates from AI ──────────────────────
  const handleProjectUpdate = (updatedProject) => {
    setProject(updatedProject);
  };

  // ─── Canvas style based on aspect ratio ──────────────────────
  const canvasStyle = useMemo(() => {
    const ar = ASPECT_RATIOS[aspectRatio];
    const ratio = ar.w / ar.h;
    return ratio >= 1
      ? { width: '100%', maxHeight: '100%', aspectRatio: `${ar.w}/${ar.h}` }
      : { height: '100%', maxWidth: '100%', aspectRatio: `${ar.w}/${ar.h}` };
  }, [aspectRatio]);

  // ─── Error state ──────────────────────
  if (loadError) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', flexDirection: 'column', gap: 16 }}>
        <h2>❌ {loadError}</h2>
        <button className="btn-primary" onClick={() => navigate('/dashboard')}>← Dashboard</button>
      </div>
    );
  }

  return (
    <div className="editor-layout">
      {/* LEFT: Video + Toolbar + Timeline */}
      <div className="editor-main">
        {/* Video Preview */}
        <div className="video-preview">
          {project?.source_url ? (
            <div className="video-canvas" style={canvasStyle}>
              <video
                ref={videoRef}
                src={project.playback_url}
                onTimeUpdate={handleTimeUpdate}
                onLoadedMetadata={handleVideoLoaded}
                onPlay={() => setIsPlaying(true)}
                onPause={() => setIsPlaying(false)}
                style={{ opacity: activeBroll ? 0.3 : 1, transition: 'opacity 0.4s ease' }}
              />
              {/* B-Roll Overlay */}
              {activeBroll && (
                <div className="broll-overlay">
                  {activeBroll.brollType === 'video' ? (
                    <video ref={brollVideoRef} src={activeBroll.src} muted autoPlay loop playsInline />
                  ) : (
                    <img src={activeBroll.src} alt="B-Roll" />
                  )}
                  <div className="broll-badge">🎞️ B-Roll: {activeBroll.keyword}</div>
                </div>
              )}
            </div>
          ) : (
            <div className="video-preview-empty">
              <span>🎬</span>
              <p>Video yüklənir...</p>
            </div>
          )}
        </div>

        {/* Pro Toolbar */}
        <div className="pro-toolbar">
          <div className="pro-toolbar-left">
            {/* Undo/Redo */}
            <button className="pro-tb-btn" title="Geri al (Ctrl+Z)" onClick={undo} disabled={undoStack.length === 0}>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 10h10a5 5 0 015 5v2" /><path d="M3 10l5-5M3 10l5 5" /></svg>
            </button>
            <button className="pro-tb-btn" title="İrəli (Ctrl+Shift+Z)" onClick={redo} disabled={redoStack.length === 0}>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 10H11a5 5 0 00-5 5v2" /><path d="M21 10l-5-5M21 10l-5 5" /></svg>
            </button>

            <div className="pro-tb-sep" />

            {/* Split */}
            <button
              className="pro-tb-btn"
              title="Kəs (S)"
              onClick={handleSplit}
              disabled={!selectedSegment || selectedSegment.locked}
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="6" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><line x1="20" y1="4" x2="8.12" y2="15.88" /><line x1="14.47" y1="14.48" x2="20" y2="20" /><line x1="8.12" y1="8.12" x2="12" y2="12" /></svg>
            </button>

            {/* Delete */}
            <button
              className="pro-tb-btn"
              title="Sil (Delete)"
              onClick={handleSegmentDelete}
              disabled={!selectedSegment || selectedSegment.locked}
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6M14 11v6" /></svg>
            </button>
          </div>

          <div className="pro-toolbar-center">
            {/* Play controls */}
            <button className="pro-tb-btn" title="1 frame geri (←)" onClick={() => handleSeek(Math.max(0, currentTime - 1 / 30))}>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="19 20 9 12 19 4" /><line x1="5" y1="19" x2="5" y2="5" /></svg>
            </button>

            <button className="pro-tb-play" onClick={togglePlay}>
              {isPlaying ? (
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" /><rect x="14" y="4" width="4" height="16" /></svg>
              ) : (
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21" /></svg>
              )}
            </button>

            <button className="pro-tb-btn" title="1 frame irəli (→)" onClick={() => handleSeek(Math.min(duration, currentTime + 1 / 30))}>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="5 4 15 12 5 20" /><line x1="19" y1="5" x2="19" y2="19" /></svg>
            </button>

            {/* Speed */}
            <select
              className="pro-tb-select"
              value={playbackSpeed}
              onChange={(e) => setPlaybackSpeed(parseFloat(e.target.value))}
              title="Playback sürəti"
            >
              <option value="0.5">0.5x</option>
              <option value="1">1x</option>
              <option value="1.5">1.5x</option>
              <option value="2">2x</option>
            </select>

            {/* Timecode */}
            <span className="pro-tb-time">{formatTime(currentTime)} / {formatTime(duration)}</span>
          </div>

          <div className="pro-toolbar-right">
            {/* Save button */}
            <button
              className={`pro-tb-btn ${hasUnsavedChanges ? 'unsaved' : ''}`}
              title={hasUnsavedChanges ? 'Yadda saxla (Ctrl+S)' : 'Dəyişiklik yoxdur'}
              onClick={saveTimeline}
              disabled={!hasUnsavedChanges || isSaving}
            >
              {isSaving ? (
                <span className="save-spinner" />
              ) : (
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" /><polyline points="17 21 17 13 7 13 7 21" /><polyline points="7 3 7 8 15 8" /></svg>
              )}
            </button>

            <div className="pro-tb-sep" />

            {/* Aspect ratio */}
            <select
              className="pro-tb-select"
              value={aspectRatio}
              onChange={(e) => setAspectRatio(e.target.value)}
              title="Aspect ratio"
            >
              {Object.entries(ASPECT_RATIOS).map(([key, val]) => (
                <option key={key} value={key}>{val.label}</option>
              ))}
            </select>

            {/* Status */}
            {project && (
              <span className={`status-badge ${project.status}`}>
                {project.status === 'uploaded' ? '📤' : project.status === 'clips_ready' ? '🎬' : project.status === 'done' ? '✅' : '⏳'}
              </span>
            )}
          </div>
        </div>

        {/* Timeline */}
        <Timeline
          segments={segments}
          duration={duration}
          currentTime={currentTime}
          onSeek={handleSeek}
          onSegmentChange={(id, updates) => { pushUndo(); handleSegmentChange(id, updates); }}
          onSegmentSelect={handleSegmentSelect}
          onSplit={handleSplit}
          onSegmentDelete={handleSegmentDelete}
          selectedSegmentId={selectedSegment?.id}
        />
      </div>

      {/* RIGHT: AI Chat */}
      <ChatPanel
        project={project}
        onProjectUpdate={handleProjectUpdate}
        selectedSegment={selectedSegment}
        onClearSelection={() => setSelectedSegment(null)}
      />
    </div>
  );
}
