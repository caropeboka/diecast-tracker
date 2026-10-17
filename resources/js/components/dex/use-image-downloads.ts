import { useCallback, useEffect, useRef, useState } from 'react';
import DexController from '@/actions/App/Http/Controllers/DexController';
import { postJson } from '@/lib/http';

/**
 * Downloads dex thumbnails in small batches while the page is open (wiki photos can't be
 * hotlinked, so each is fetched once and kept). Starts by itself when some are missing.
 */
export function useImageDownloads(
    initialPending: number,
    {
        catalogId,
        onBatch,
    }: {
        catalogId?: number;
        /** After each batch, with how many are left. */
        onBatch?: (remaining: number) => void;
    } = {},
) {
    const [pending, setPending] = useState(initialPending);
    const [running, setRunning] = useState(false);
    const [failed, setFailed] = useState(false);
    // Each loop has an id; bumping `current` cancels the running one after its batch.
    const current = useRef(0);
    const live = useRef(-1);
    const onBatchRef = useRef(onBatch);

    useEffect(() => {
        onBatchRef.current = onBatch;
    }, [onBatch]);

    const start = useCallback(async () => {
        if (live.current === current.current) {
            return;
        }

        const id = ++current.current;
        live.current = id;
        setRunning(true);
        setFailed(false);

        try {
            let remaining = Infinity;

            while (current.current === id && remaining > 0) {
                const result = await postJson<{ remaining: number }>(
                    DexController.images.url(),
                    { catalog_id: catalogId ?? null },
                );

                // No progress at all: stop instead of looping.
                if (result.remaining >= remaining) {
                    break;
                }

                remaining = result.remaining;
                setPending(remaining);
                onBatchRef.current?.(remaining);
            }
        } catch {
            setFailed(true);
        } finally {
            if (live.current === id) {
                live.current = -1;
                setRunning(false);
            }
        }
    }, [catalogId]);

    // New photos to fetch (e.g. after a sync): pick up the new count and start.
    useEffect(() => {
        setPending(initialPending);

        if (initialPending > 0) {
            void start();
        }
    }, [initialPending, start]);

    // Leaving the page stops after the current batch.
    useEffect(
        () => () => {
            current.current++;
        },
        [],
    );

    return { pending, running, failed, start };
}
