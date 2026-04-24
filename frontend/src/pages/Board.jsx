// C:\xampp\htdocs\metro-north-board\frontend\src\pages\Board.jsx
import { useState, useEffect, useRef } from 'react';
import { useTrainData } from '../hooks/useTrainData';
import SectionHeader from '../components/SectionHeader';
import TrainRow from '../components/TrainRow';
import FlipBoard from '../components/FlipBoard';
import './Board.css';

function VestaClock() {
  const [parts, setParts] = useState(() => timeParts(new Date()));

  useEffect(() => {
    const t = setInterval(() => setParts(timeParts(new Date())), 1000);
    return () => clearInterval(t);
  }, []);

  return (
    <div className="vesta-clock">
      <FlipBoard value={parts.hhmm} minLength={5} />
      <FlipBoard value={parts.ss}   minLength={2} />
      <FlipBoard value={parts.ampm} minLength={2} />
    </div>
  );
}

function timeParts(d) {
  const h    = d.getHours();
  const m    = d.getMinutes();
  const s    = d.getSeconds();
  const ampm = h >= 12 ? 'PM' : 'AM';
  const h12  = h % 12 || 12;
  return {
    hhmm: `${String(h12).padStart(2, '0')}:${String(m).padStart(2, '0')}`,
    ss:   `:${String(s).padStart(2, '0')}`,
    ampm: ` ${ampm}`,
  };
}

function TrainSection({ title, trains, flipKeys }) {
  return (
    <div className="board-section">
      <SectionHeader destination={title} />
      <div className="board-column-labels">
        <span>TRAIN</span>
        <span>DEPARTS / ARRIVES</span>
        <span>TRACK</span>
        <span>STATUS</span>
      </div>
      <div className="board-rows">
        {!trains || trains.length === 0 ? (
          <div className="board-no-trains">NO DEPARTURES SCHEDULED</div>
        ) : (
          trains.map((t, i) => (
            <TrainRow
              key={i}
              train={t.train}
              time={t.time}
              status={t.status}
              platform={t.platform ?? null}
              peak={t.peak ?? null}
              bikes={t.bikes ?? null}
              stops={t.stops ?? null}
              showCountdown={i === 0}
              flipKey={flipKeys ? flipKeys[i] : 0}
            />
          ))
        )}
      </div>
    </div>
  );
}

export default function Board() {
  const { data, loading, error, lastUpdated } = useTrainData();

  const [nhFlipKeys,  setNhFlipKeys]  = useState([0, 0, 0]);
  const [nycFlipKeys, setNycFlipKeys] = useState([0, 0, 0]);
  const nhActiveRef  = useRef(0);
  const nycActiveRef = useRef(1);
  const nycTimerRef  = useRef(null);

  useEffect(() => {
    const interval = setInterval(() => {
      setNhFlipKeys(prev => {
        const next = [...prev];
        next[nhActiveRef.current]++;
        nhActiveRef.current = (nhActiveRef.current + 1) % 3;
        return next;
      });
      nycTimerRef.current = setTimeout(() => {
        setNycFlipKeys(prev => {
          const next = [...prev];
          next[nycActiveRef.current]++;
          nycActiveRef.current = (nycActiveRef.current + 1) % 3;
          return next;
        });
      }, 5000);
    }, 30000);

    return () => {
      clearInterval(interval);
      clearTimeout(nycTimerRef.current);
    };
  }, []);

  return (
    <div className="board-page">

      <header className="board-header">
        <img src="/logo.jpeg" alt="Deep 6 Arcade" className="board-logo" />
        <div className="board-title">
          <span className="board-title-main">METRO NORTH RAILROAD</span>
          <span className="board-title-sub">STRATFORD &middot; DEPARTURES / ARRIVALS</span>
        </div>
        <VestaClock />
      </header>

      {loading && !data ? (
        <div className="board-loading">LOADING&#8230;</div>
      ) : (
        <main className="board-main">
          <TrainSection title="NEW HAVEN CT"  trains={data?.to_new_haven} flipKeys={nhFlipKeys} />
          <div className="board-section-sep" />
          <TrainSection title="NEW YORK CITY" trains={data?.to_nyc}       flipKeys={nycFlipKeys} />
        </main>
      )}

      {error && (
        <div className="board-error-banner">
          WARNING &mdash; CONNECTION INTERRUPTED &mdash; SHOWING LAST KNOWN DATA
        </div>
      )}

      <footer className="board-footer">
        <div className="board-footer-note">
          *Departure times automatically update to account for delays.
        </div>
        <div className="board-footer-main">
          <span>
            {lastUpdated
              ? `UPDATED ${lastUpdated.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`
              : 'CONNECTING…'}
          </span>
          <span>MTA METRO-NORTH RAILROAD</span>
        </div>
      </footer>

    </div>
  );
}
