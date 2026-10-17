import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Car, Check, CircleSlash, Loader2, Wand2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import DexController from '@/actions/App/Http/Controllers/DexController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import { DexEntryCard } from '@/components/dex/dex-entry-card';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { errorMessage, postJson } from '@/lib/http';
import { STATUS_LABELS } from '@/types/diecast';
import type { DiecastStatus } from '@/types/diecast';
import type { DexRef } from '@/types/dex';

type ReviewItem = {
    diecast: {
        id: number;
        name: string;
        brand: string | null;
        series: string | null;
        collection_number: string | null;
        year: number | null;
        status: DiecastStatus;
        photo_url: string | null;
        catalog_match: 'auto' | null;
    };
    linked: DexRef | null;
    suggestions: DexRef[];
};

export default function DexMatches({
    items,
    total,
}: {
    items: ReviewItem[];
    total: number;
}) {
    const [done, setDone] = useState<Set<number>>(new Set());
    const left = items.filter((i) => !done.has(i.diecast.id));

    return (
        <>
            <Head title="Cocokkan Dex" />
            <div className="space-y-5 p-4 md:p-6">
                <Link
                    href={DexController.index()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Dex
                </Link>
                <Heading
                    title="Cocokkan dengan Dex"
                    description="Cek entri Dex untuk setiap diecast. Yang dicocokkan otomatis cukup dikonfirmasi; tahun yang keliru bisa langsung diperbaiki."
                />

                {left.length === 0 ? (
                    <div className="surface rounded-xl p-8 text-center text-sm text-muted-foreground">
                        <Check className="mx-auto mb-2 size-8 text-brand" />
                        Semua sudah dicek.
                        {total > items.length && (
                            <p className="mt-1">
                                Muat ulang halaman untuk {total - items.length}{' '}
                                diecast berikutnya.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {left.map((item) => (
                            <ReviewCard
                                key={item.diecast.id}
                                item={item}
                                onDone={() =>
                                    setDone((all) => new Set(all).add(item.diecast.id))
                                }
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function ReviewCard({ item, onDone }: { item: ReviewItem; onDone: () => void }) {
    const { diecast, linked, suggestions } = item;
    const [busy, setBusy] = useState<string | null>(null);

    async function act(
        key: string,
        body: { action: 'confirm' | 'link' | 'ignore'; catalog_item_id?: number; fix_year?: boolean },
        message: string,
    ) {
        setBusy(key);

        try {
            await postJson(DexController.link.url(diecast.id), body);
            toast.success(message);
            onDone();
        } catch (error) {
            toast.error(errorMessage(error));
            setBusy(null);
        }
    }

    return (
        <article className="surface grid gap-4 rounded-xl p-4 md:grid-cols-[minmax(0,260px)_1fr]">
            <Link href={DiecastController.edit(diecast.id)} className="flex items-start gap-3">
                {diecast.photo_url ? (
                    <img src={diecast.photo_url} alt="" className="size-16 shrink-0 rounded-md object-cover" />
                ) : (
                    <span className="flex size-16 shrink-0 items-center justify-center rounded-md bg-muted">
                        <Car className="size-6 text-muted-foreground" />
                    </span>
                )}
                <span className="min-w-0">
                    <span className="block font-medium">{diecast.name}</span>
                    <span className="block text-xs text-muted-foreground">
                        {[diecast.brand, diecast.series].filter(Boolean).join(' · ')}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {[diecast.collection_number, diecast.year].filter(Boolean).join(' · ') || 'Tanpa nomor'} ·{' '}
                        {STATUS_LABELS[diecast.status]}
                    </span>
                </span>
            </Link>

            <div className="space-y-2">
                {linked ? (
                    <>
                        <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <Wand2 className="size-3.5 text-brand" /> Dicocokkan otomatis
                        </p>
                        <DexEntryCard entry={linked} />
                        <div className="flex flex-wrap gap-2">
                            {linked.year_fix ? (
                                <Button
                                    size="sm"
                                    disabled={busy !== null}
                                    onClick={() =>
                                        void act('fix', { action: 'confirm', fix_year: true }, `Benar, tahun diubah ke ${linked.year_fix}.`)
                                    }
                                >
                                    {busy === 'fix' ? <Loader2 className="animate-spin" /> : <Check />}
                                    Benar & ubah tahun ke {linked.year_fix}
                                </Button>
                            ) : null}
                            <Button
                                size="sm"
                                variant={linked.year_fix ? 'outline' : 'default'}
                                disabled={busy !== null}
                                onClick={() => void act('confirm', { action: 'confirm' }, 'Dikonfirmasi.')}
                            >
                                {busy === 'confirm' ? <Loader2 className="animate-spin" /> : <Check />}
                                Benar{linked.year_fix ? ', tahun tetap' : ''}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={busy !== null}
                                onClick={() => void act('ignore', { action: 'ignore' }, 'Dilepas dari Dex.')}
                            >
                                <CircleSlash /> Bukan ini
                            </Button>
                        </div>
                        {linked.year_fix && (
                            <p className="text-xs text-muted-foreground">
                                Di koleksi tercatat {diecast.year ?? 'tanpa tahun'}, tapi rilis ini dari {linked.year_fix}.
                                Tahun di kartu biasanya tahun hak cipta model, bukan tahun rilis.
                            </p>
                        )}
                    </>
                ) : suggestions.length ? (
                    <>
                        <p className="text-xs font-medium text-muted-foreground">Mungkin salah satu ini:</p>
                        {suggestions.map((s) => (
                            <div key={s.item_id} className="flex flex-wrap items-center gap-2">
                                <DexEntryCard entry={s} className="min-w-0 flex-1" />
                                <Button
                                    size="sm"
                                    disabled={busy !== null}
                                    onClick={() =>
                                        void act(
                                            `link-${s.item_id}`,
                                            { action: 'link', catalog_item_id: s.item_id, fix_year: s.year_fix !== null },
                                            s.year_fix ? `Ditautkan, tahun diubah ke ${s.year_fix}.` : 'Ditautkan.',
                                        )
                                    }
                                >
                                    {busy === `link-${s.item_id}` ? <Loader2 className="animate-spin" /> : <Check />}
                                    Ini dia
                                </Button>
                            </div>
                        ))}
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy !== null}
                            onClick={() => void act('ignore', { action: 'ignore' }, 'Ditandai tidak ada di Dex.')}
                        >
                            <CircleSlash /> Tidak ada di sini
                        </Button>
                    </>
                ) : (
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        Tidak ditemukan di Dex yang sudah disinkron.
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy !== null}
                            onClick={() => void act('ignore', { action: 'ignore' }, 'Ditandai tidak ada di Dex.')}
                        >
                            <CircleSlash /> Lewati
                        </Button>
                    </div>
                )}
            </div>
        </article>
    );
}

DexMatches.layout = {
    breadcrumbs: [
        { title: 'Dex', href: DexController.index() },
        { title: 'Cocokkan', href: DexController.matches() },
    ],
};
