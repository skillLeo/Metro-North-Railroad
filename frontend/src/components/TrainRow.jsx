import FlipBoard from './FlipBoard';
import './TrainRow.css';

function statusClass(status) {
  if (!status) return 'status-ontime';
  const s = status.toLowerCase();
  if (s.includes('cancel')) return 'status-cancelled';
  if (s.includes('delay')) return 'status-delayed';
  return 'status-ontime';
}

export default function TrainRow({ train, time, status }) {
  return (
    <div className="train-row">
      <div className="train-col train-number">
        <FlipBoard value={String(train)} minLength={4} />
      </div>
      <div className="train-col train-time">
        <FlipBoard value={String(time)} minLength={8} />
      </div>
      <div className={`train-col train-status ${statusClass(status)}`}>
        <FlipBoard value={String(status)} minLength={14} />
      </div>
    </div>
  );
}
