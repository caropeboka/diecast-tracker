import { Link } from '@inertiajs/react';
import { BookMarked, Check, CircleSlash, Loader2, Wand2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import DexController from '@/actions/App/Http/Controllers/DexController';
import { DexEntryCard } from '@/components/dex/dex-entry-card';
import { Button } from '@/components/ui/button';
import { errorMessage, postJson } from '@/lib/http';
import type { DexLinkState, DexRef } from '@/types/dex';

/** "Pakai tahun rilis 2026" when the form's year differs from the entry's release year. */
function YearFix({
    entry,
    year,
    onYear,
}: {
    entry: DexRef;
    year: string;
    onYear: (year: number) => void;
}) {
    if (!entry.year || String(entry.year) === year) {
        return null;
    }

    return (
        <div className="rounded-md bg-muted/60 p-2 text-xs text-muted-foreground">
            Tahun di form {year || 'kosong'}, tapi rilis ini dari{' '}
            <strong className="text-foreground">{entry.year}</strong> (tahun di
            kartu biasanya tahun hak cipta model).{' '}
            <button
                type="button"
                onClick={() => onYear(entry.year!)}
                className="font-medium text-brand underline-offset-2 hover:underline"
            >
                Pakai {entry.year}
            </button>
        </div>
    );
}

/**
 * The diecast's dex entry on the edit page: confirm an automatic match, pick a suggestion,
 * or drop it. Changes are saved right away (they're not part of the form).
 */
export function DexEditPanel({
    diecastId,
    initial,
    year,
    onYear,
}: {
    diecastId: number;
    initial: DexLinkState;
    year: string;
    onYear: (year: number) => void;
}) {
    const [state, setState] = useState(initial);
    const [busy, setBusy] = useState<string | null>(null);

    async function act(
        key: string,
        body: { action: 'confirm' | 'link' | 'ignore'; catalog_item_id?: number },
    ) {
        setBusy(key);

        try {
            const result = await postJson<{
                linked: DexRef | null;
                catalog_match: DexLinkState['match'];
            }>(DexController.link.url(diecastId), body);
            setState({
                linked: result.linked,
                match: result.catalog_match,
                suggestions: body.action === 'ignore' ? [] : state.suggestions,
            });
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setBusy(null);
    }

    return (
        <section className="space-y-2 rounded-lg border border-border/70 p-3">
            <p className="flex items-center gap-1.5 text-sm font-medium">
                <BookMarked className="size-4 text-brand" /> Dex
            </p>

            {state.linked ? (
                <>
                    <DexEntryCard entry={state.linked} />
                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        {state.match === 'auto' ? (
                            <>
                                <span className="flex items-center gap-1">
                                    <Wand2 className="size-3.5" /> Dicocokkan
                                    otomatis
                                </span>
                                <Button
                                    size="sm"
                                    type="button"
                                    disabled={busy !== null}
                                    onClick={() => void act('confirm', { action: 'confirm' })}
                                >
                                    {busy === 'confirm' ? <Loader2 className="animate-spin" /> : <Check />}
                                    Benar
                                </Button>
                            </>
                        ) : (
                            <span className="flex items-center gap-1">
                                <Check className="size-3.5 text-brand" /> Terkonfirmasi
                            </span>
                        )}
                        <Button
                            size="sm"
                            type="button"
                            variant="ghost"
                            disabled={busy !== null}
                            onClick={() => void act('ignore', { action: 'ignore' })}
                        >
                            <CircleSlash /> Lepas
                        </Button>
                    </div>
                    <YearFix entry={state.linked} year={year} onYear={onYear} />
                </>
            ) : state.suggestions.length ? (
                <>
                    <p className="text-xs text-muted-foreground">Mungkin salah satu ini:</p>
                    {state.suggestions.map((s) => (
                        <div key={s.item_id} className="flex items-center gap-2">
                            <DexEntryCard entry={s} className="min-w-0 flex-1" />
                            <Button
                                size="sm"
                                type="button"
                                disabled={busy !== null}
                                onClick={() =>
                                    void act(`link-${s.item_id}`, {
                                        action: 'link',
                                        catalog_item_id: s.item_id,
                                    })
                                }
                            >
                                {busy === `link-${s.item_id}` ? <Loader2 className="animate-spin" /> : <Check />}
                                Ini
                            </Button>
                        </div>
                    ))}
                </>
            ) : (
                <p className="text-xs text-muted-foreground">
                    {state.match === 'ignored'
                        ? 'Ditandai tidak ada di Dex.'
                        : 'Belum ada di Dex.'}{' '}
                    <Link
                        href={DexController.index()}
                        className="text-brand underline-offset-2 hover:underline"
                    >
                        Buka Dex
                    </Link>
                </p>
            )}
        </section>
    );
}

/**
 * On the add form: the entry this new diecast will be linked to (from the Dex page, or the AI
 * scan's suggestion once accepted).
 */
export function DexCreatePanel({
    chosen,
    suggestion,
    year,
    onChoose,
    onYear,
}: {
    chosen: DexRef | null;
    suggestion: DexRef | null;
    year: string;
    onChoose: (entry: DexRef | null) => void;
    onYear: (year: number) => void;
}) {
    if (chosen) {
        return (
            <section className="space-y-2 rounded-lg border border-brand/40 bg-brand/5 p-3">
                <div className="flex items-center justify-between gap-2">
                    <p className="flex items-center gap-1.5 text-sm font-medium">
                        <BookMarked className="size-4 text-brand" /> Ditautkan ke Dex
                    </p>
                    <button
                        type="button"
                        onClick={() => onChoose(null)}
                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <X className="size-3.5" /> Lepas
                    </button>
                </div>
                <DexEntryCard entry={chosen} />
                <YearFix entry={chosen} year={year} onYear={onYear} />
            </section>
        );
    }

    if (!suggestion) {
        return null;
    }

    const yearDiffers = suggestion.year && String(suggestion.year) !== year;

    return (
        <section className="space-y-2 rounded-lg border border-border/70 p-3">
            <p className="flex items-center gap-1.5 text-sm font-medium">
                <BookMarked className="size-4 text-brand" /> Sepertinya ini di Dex
            </p>
            <DexEntryCard entry={suggestion} />
            <Button
                type="button"
                size="sm"
                onClick={() => {
                    onChoose(suggestion);

                    if (yearDiffers) {
                        onYear(suggestion.year!);
                    }
                }}
            >
                <Check />
                {yearDiffers ? `Tautkan & pakai tahun ${suggestion.year}` : 'Tautkan'}
            </Button>
            {yearDiffers && (
                <p className="text-xs text-muted-foreground">
                    AI membaca tahun {year || '—'} dari kartu; rilisnya {suggestion.year}.
                </p>
            )}
        </section>
    );
}
