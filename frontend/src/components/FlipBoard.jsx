// C:\xampp\htdocs\metro-north-board\frontend\src\components\FlipBoard.jsx
import { useEffect, useRef, useState } from 'react';
import './FlipBoard.css';

const FLIP_MS = 80;

function FlipChar({ target, delay, flipKey }) {
  const targetUpper = target.toUpperCase();
  const displayChar = targetUpper === ' ' ? ' ' : targetUpper;

  const [shown, setShown]         = useState(displayChar);
  const [animating, setAnimating] = useState(false);
  const prevRef     = useRef(null);
  const prevFlipKey = useRef(flipKey);
  const timerRef    = useRef(null);

  useEffect(() => {
    clearTimeout(timerRef.current);

    const valueChanged = prevRef.current !== targetUpper;
    const keyChanged   = prevFlipKey.current !== flipKey;

    if (!valueChanged && !keyChanged) return;

    prevRef.current     = targetUpper;
    prevFlipKey.current = flipKey;

    timerRef.current = setTimeout(() => {
      setAnimating(true);
      timerRef.current = setTimeout(() => {
        setShown(displayChar);
        setAnimating(false);
      }, FLIP_MS / 2);
    }, delay);

    return () => clearTimeout(timerRef.current);
  }, [target, delay, flipKey, targetUpper, displayChar]);

  const isSpace = shown === ' ';
  return (
    <span className={`flip-char${animating ? ' flip-animating' : ''}${isSpace ? ' flip-space' : ''}`}>
      {shown}
    </span>
  );
}

export default function FlipBoard({ value = '', minLength = 0, flipKey }) {
  const str    = value.toUpperCase();
  const padded = str.padEnd(Math.max(minLength, str.length), ' ');

  return (
    <span className="flip-board">
      {padded.split('').map((char, i) => (
        <FlipChar key={i} target={char} delay={i * 30} flipKey={flipKey} />
      ))}
    </span>
  );
}
