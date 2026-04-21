import { useState, useEffect } from 'react';
import { useTrainData } from '../hooks/useTrainData';
import SectionHeader from '../components/SectionHeader';
import TrainRow from '../components/TrainRow';
import './Board.css';

const DUMMY = {
  to_new_haven: [
    { train: '1274', time: '3:42 PM', status: 'On Time' },
    { train: '1276', time: '4:18 PM', status: 'Delayed 8 min' },
    { train: '1278', time: '5:02 PM', status: 'On Time' },
  ],
  to_nyc: [
    { train: '1201', time: '3:55 PM', status: 'On Time' },
    { train: '1205', time: '4:30 PM', status: 'On Time' },
    { train: '1209', time: '5:15 PM', status: 'Cancelled' },
  ],
};

function Clock() {
  const [time, setTime] = useState(() => formatTime(new Date()));

  useEffect(() => {
    const t = setInterval(() => setTime(formatTime(new Date())), 1000);
    return () => clearInterval(t);
  }, []);

  return <span className="clock-display">{time}</span>;
}

function formatTime(d) {
  return d.toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

function TrainSection({ title, trains }) {
  return (
    <div className="board-section">
      <SectionHeader destination={title} />
      <div className="board-column-labels">
        <span>TRAIN</span>
        <span>DEPARTS</span>
        <span>STATUS</span>
      </div>
      <div className="board-rows">
        {!trains || trains.length === 0 ? (
          <div className="board-no-trains">NO DEPARTURES SCHEDULED</div>
        ) : (
          trains.map((t, i) => (
            <TrainRow key={i} train={t.train} time={t.time} status={t.status} />
          ))
        )}
      </div>
    </div>
  );
}

export default function Board() {
  const { data, loading, error, lastUpdated } = useTrainData();
  const board = data ?? DUMMY;

  return (
    <div className="board-page">
      <header className="board-header">
        <div className="board-title">
          <span className="board-title-main">METRO NORTH RAILROAD</span>
          <span className="board-title-sub">STRATFORD · LIVE DEPARTURES</span>
        </div>
        <Clock />
      </header>

      {loading && !board ? (
        <div className="board-loading">
          <span>LOADING&#8230;</span>
        </div>
      ) : (
        <main className="board-main">
          <TrainSection title="NEW HAVEN CT" trains={board?.to_new_haven} />
          <div className="board-divider" />
          <TrainSection title="NEW YORK CITY" trains={board?.to_nyc} />
        </main>
      )}

      {error && (
        <div className="board-error-banner">
          ⚠ CONNECTION INTERRUPTED — SHOWING LAST KNOWN DATA
        </div>
      )}

      <footer className="board-footer">
        <span>
          {lastUpdated
            ? `UPDATED ${lastUpdated.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`
            : 'LOADING\u2026'}
        </span>
        <span>MTA METRO-NORTH RAILROAD</span>
      </footer>
    </div>
  );
}
