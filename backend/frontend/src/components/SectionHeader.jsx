import './SectionHeader.css';

export default function SectionHeader({ destination }) {
  return (
    <div className="section-header">
      <span className="section-arrow">&#9658;</span>
      <span className="section-destination">{destination}</span>
    </div>
  );
}
