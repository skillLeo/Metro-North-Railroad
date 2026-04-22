import { useState, useEffect } from 'react';
import FlipBoard from './FlipBoard';
import './TrainRow.css';

function statusClass(status) {
  if (!status) return 'status-ontime';
  const s = status.toLowerCase();
  if (s.includes('cancel')) return 'status-cancelled';
  if (s.includes('delay')) return 'status-delayed';
  return 'status-ontime';
}

function parseMs(timeStr) {
  if (!timeStr) return null;
  const match = timeStr.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
  if (!match) return null;
  let h = parseInt(match[1], 10);
  const m = parseInt(match[2], 10);
  const ampm = match[3].toUpperCase();
  if (ampm === 'PM' && h !== 12) h += 12;
  if (ampm === 'AM' && h === 12) h = 0;
  const now = new Date();
  const dep = new Date(now.getFullYear(), now.getMonth(), now.getDate(), h, m, 0, 0);
  const diff = dep - now;
  return diff > 0 ? diff : null;
}

function Countdown({ time }) {
  const [display, setDisplay] = useState('');
  useEffect(() => {
    function tick() {
      const ms = parseMs(time);
      if (ms === null || ms > 60 * 60 * 1000) { setDisplay(''); return; }
      const totalSecs = Math.floor(ms / 1000);
      const mins = Math.floor(totalSecs / 60);
      const secs = totalSecs % 60;
      setDisplay(`${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`);
    }
    tick();
    const t = setInterval(tick, 1000);
    return () => clearInterval(t);
  }, [time]);
  if (!display) return null;
  return <span className="train-countdown">{display}</span>;
}

export default function TrainRow({ train, time, status, flipKey, showCountdown, platform, peak, bikes, stops }) {
  return (
    <div className="train-row-wrapper">

      {/* ── Main row ── */}
      <div className="train-row">
        <div className="train-col train-number">
          <FlipBoard value={String(train)} minLength={4} flipKey={flipKey} />
        </div>
        <div className="train-col train-time-col">
          <FlipBoard value={String(time)} minLength={8} flipKey={flipKey} />
          {showCountdown && <Countdown time={time} />}
        </div>
        <div className={`train-col train-status ${statusClass(status)}`}>
          <FlipBoard value={String(status)} minLength={14} flipKey={flipKey} />
        </div>
        <div className="train-col train-right">
          {peak != null && (
            <span className={`meta-badge ${peak ? 'badge-peak' : 'badge-offpeak'}`}>
              {peak ? 'PEAK' : 'OFF-PK'}
            </span>
          )}
          {bikes != null && (
            <span className={`meta-badge ${bikes ? 'badge-bikes' : 'badge-nobikes'}`}>
              {bikes ? 'BIKES' : 'NO BIKE'}
            </span>
          )}
          {platform != null && (
            <span className="train-platform">TRK {platform}</span>
          )}
        </div>
      </div>

      {/* ── Stops row ── */}
      {stops && stops.length > 0 && (
        <div className="train-stops">
          {stops.map((s, i) => (
            <span key={i} className="train-stop">
              {i > 0 && <span className="stop-sep">&middot;</span>}
              {s}
            </span>
          ))}
        </div>
      )}

    </div>
  );
}
