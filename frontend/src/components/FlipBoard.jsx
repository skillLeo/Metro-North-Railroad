import { useEffect, useRef, useState } from 'react';
import './FlipBoard.css';

const FLIP_MS = 80;

function FlipChar({ target, delay }) {
  const targetUpper = target.toUpperCase();
  const displayChar = targetUpper === ' ' ? '\u00A0' : targetUpper;

  const [shown, setShown]         = useState(displayChar);
  const [animating, setAnimating] = useState(false);
  const prevRef  = useRef(null);
  const timerRef = useRef(null);

  useEffect(() => {
    clearTimeout(timerRef.current);

    if (prevRef.current === targetUpper) return;
    prevRef.current = targetUpper;

    timerRef.current = setTimeout(() => {
      setAnimating(true);
      timerRef.current = setTimeout(() => {
        setShown(displayChar);
        setAnimating(false);
      }, FLIP_MS / 2);
    }, delay);

    return () => clearTimeout(timerRef.current);
  }, [target, delay, targetUpper, displayChar]);

  const isSpace = shown === '\u00A0';
  return (
    <span className={`flip-char${animating ? ' flip-animating' : ''}${isSpace ? ' flip-space' : ''}`}>
      {shown}
    </span>
  );
}

export default function FlipBoard({ value = '', minLength = 0 }) {
  const str    = value.toUpperCase();
  const padded = str.padEnd(Math.max(minLength, str.length), ' ');

  return (
    <span className="flip-board">
      {padded.split('').map((char, i) => (
        <FlipChar key={i} target={char} delay={i * 30} />
      ))}
    </span>
  );
}
