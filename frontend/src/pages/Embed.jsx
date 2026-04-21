import { useTrainData } from '../hooks/useTrainData';
import SectionHeader from '../components/SectionHeader';
import TrainRow from '../components/TrainRow';
import './Embed.css';

function TrainSection({ title, trains }) {
  return (
    <div className="embed-section">
      <SectionHeader destination={title} />
      <div className="embed-rows">
        {!trains || trains.length === 0 ? (
          <div className="embed-no-trains">NO DEPARTURES SCHEDULED</div>
        ) : (
          trains.map((t, i) => (
            <TrainRow key={i} train={t.train} time={t.time} status={t.status} />
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
        <span className="embed-title">METRO NORTH · STRATFORD DEPARTURES</span>
        {error && <span className="embed-error-dot" title="Connection interrupted" />}
      </div>

      {loading && !data ? (
        <div className="embed-loading">LOADING&#8230;</div>
      ) : (
        <div className="embed-main">
          <TrainSection title="NEW HAVEN CT" trains={data?.to_new_haven} />
          <TrainSection title="NEW YORK CITY" trains={data?.to_nyc} />
        </div>
      )}

      <div className="embed-footer">LIVE · UPDATES EVERY 15 SEC</div>
    </div>
  );
}
