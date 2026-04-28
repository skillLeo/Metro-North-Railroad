// C:\xampp\htdocs\metro-north-board\frontend\src\pages\Embed.jsx
import { useTrainData } from '../hooks/useTrainData';
import SectionHeader from '../components/SectionHeader';
import TrainRow from '../components/TrainRow';
import './Embed.css';

function TrainSection({ title, trains }) {
  return (
    <div className="embed-section">
      <SectionHeader destination={title} />
      <div className="embed-column-labels">
        <span>TRAIN</span>
        <span>DEPARTS / ARRIVES</span>
        <span>STATUS</span>
      </div>
      <div className="embed-rows">
        {!trains || trains.length === 0 ? (
          <div className="embed-no-trains">NO DEPARTURES SCHEDULED</div>
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
              stops={null}
              showCountdown={i === 0}
            />
          ))
        )}
      </div>
    </div>
  );
}

export default function Embed() {
  const { data, loading, error } = useTrainData();

  return (
    <div className="embed-page">
      <div className="embed-header">
        <span className="embed-title">METRO NORTH &middot; STRATFORD DEPARTURES/ARRIVALS</span>
        {error && <span className="embed-error-dot" title="Connection interrupted" />}
      </div>

      {loading && !data ? (
        <div className="embed-loading">LOADING&#8230;</div>
      ) : (
        <div className="embed-main">
          <TrainSection title="NEW HAVEN CT"  trains={data?.to_new_haven} />
          <TrainSection title="NEW YORK CITY" trains={data?.to_nyc} />
        </div>
      )}

      <div className="embed-footer">LIVE &middot; UPDATES EVERY 15 SEC</div>
    </div>
  );
}
