import { useState, useEffect, useRef } from 'react';

const API_URL = '/api/board';
const POLL_INTERVAL = 15000;

export function useTrainData() {
  const [data, setData]           = useState(null);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [lastUpdated, setLastUpdated] = useState(null);
  const lastDataRef = useRef(null);

  useEffect(() => {
    let cancelled = false;

    async function fetchData() {
      try {
        const res = await fetch(API_URL);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (!cancelled) {
          setData(json);
          lastDataRef.current = json;
          setError(null);
          setLoading(false);
          setLastUpdated(new Date());
        }
      } catch (err) {
        if (!cancelled) {
          setError(err.message);
          if (lastDataRef.current) setData(lastDataRef.current);
          setLoading(false);
        }
      }
    }

    fetchData();
    const interval = setInterval(fetchData, POLL_INTERVAL);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  return { data, loading, error, lastUpdated };
}
