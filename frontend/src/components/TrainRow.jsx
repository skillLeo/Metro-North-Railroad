// C:\xampp\htdocs\metro-north-board\frontend\src\components\TrainRow.jsx
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
  let diff = dep - now;
  // Time already passed today — check if it's tomorrow's train
  if (diff <= 0) {
    dep.setDate(dep.getDate() + 1);
    diff = dep - now;
  }
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
  return (
    <div className="train-countdown-wrap">
      <span className="train-countdown-label">NEXT IN</span>
      <FlipBoard value={display} minLength={5} />
    </div>
  );
}

export default function TrainRow({ train, time, status, flipKey, showCountdown, platform, peak, bikes, stops }) {
  return (
    <div className="train-row-wrapper">

      {/* ── Main row: TRAIN | DEPARTS | META | STATUS ── */}
      <div className="train-row">

        {/* col 1 — train number */}
        <div className="train-col train-number">
          <FlipBoard value={String(train)} minLength={4} flipKey={flipKey} />
        </div>

        {/* col 2 — departure time + countdown inline */}
        <div className="train-col train-time-col">
          <FlipBoard value={String(time)} minLength={8} flipKey={flipKey} />
          {showCountdown && <Countdown time={time} />}
        </div>

        {/* col 3 — peak/bikes | track | arrival/departure */}
        <div className="train-col train-meta">
          {/* Peak + Bikes side by side */}
          {(peak != null || bikes != null) && (
            <div className="train-meta-badges">
              {peak != null && (
                <span className={`meta-badge ${peak ? 'badge-peak' : 'badge-offpeak'}`}>
                  {peak ? 'PEAK' : 'OFF-PEAK'}
                </span>
              )}
              {bikes != null && (
                <span className={`meta-badge ${bikes ? 'badge-bikes' : 'badge-nobikes'}`}>
                  {bikes ? 'BIKES' : 'NO BIKES'}
                </span>
              )}
            </div>
          )}
          {platform != null && (
            <span className="train-platform">TRACK {platform}</span>
          )}
          {/* Arrival + Departure side by side — right before status column */}
          <div className="train-meta-badges">
            <span className="meta-badge badge-arrival">ARRIVAL</span>
            <span className="meta-badge badge-departure">DEPARTURE</span>
          </div>
        </div>

        {/* col 4 — status (rightmost) */}
        <div className={`train-col train-status ${statusClass(status)}`}>
          <FlipBoard value={String(status)} minLength={12} flipKey={flipKey} />
        </div>

      </div>

      {/* ── Stops — vertical list; 3-col grid when stops > 4 (NYC-bound) ── */}
      {stops && stops.length > 0 && (
        <div className={`train-stops${stops.length > 4 ? ' train-stops-grid' : ''}`}>
          {stops.map((s, i) => (
            <div key={i} className="train-stop-row">
              <span className="stop-bullet">&#8250;</span>
              <FlipBoard value={s} minLength={s.length} flipKey={flipKey} />
            </div>
          ))}
        </div>
      )}

    </div>
  );
}
