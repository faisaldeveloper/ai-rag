import { useCallback, useEffect, useState } from 'react';

/** Count down whole seconds to zero, e.g. to honour a Retry-After header. */
export function useCountdown(): [number, (seconds: number) => void] {
    const [remaining, setRemaining] = useState(0);

    useEffect(() => {
        if (remaining <= 0) {
            return;
        }

        const timer = window.setTimeout(() => setRemaining((value) => value - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [remaining]);

    const start = useCallback((seconds: number) => setRemaining(Math.max(0, Math.ceil(seconds))), []);

    return [remaining, start];
}
