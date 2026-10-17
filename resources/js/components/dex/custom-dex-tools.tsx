import { router, useForm } from '@inertiajs/react';
import {
    FileSpreadsheet,
    ImagePlus,
    Loader2,
    Plus,
    Sparkles,
    Trash2,
    Upload,
    Warehouse,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import CustomDexController from '@/actions/App/Http/Controllers/CustomDexController';
import DexController from '@/actions/App/Http/Controllers/DexController';
import { Panel } from '@/components/dashboard/charts';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage, postJson } from '@/lib/http';

/** "Buat dex sendiri": for brands without a wiki list (Mini GT, Tarmac, Roll Speed…). */
export function CreateDexPanel({ brands }: { brands: string[] }) {
    const form = useForm({ brand: brands[0] ?? '', name: '', year: '' });

    return (
        <Panel title="Dex sendiri" icon={<Plus className="size-4" />}>
            <p className="mb-3 text-sm text-muted-foreground">
                Untuk merek yang tidak punya daftar di wiki (Mini GT, Tarmac,
                Inno64, Roll Speed…). Isi daftarnya manual, dari Excel, dari
                screenshot checklist (dibaca AI), atau dari koleksimu.
            </p>
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({ ...data, year: data.year || null }));
                    form.submit(CustomDexController.store());
                }}
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="dex-brand">Merek</Label>
                    <Input
                        id="dex-brand"
                        list="dex-brand-options"
                        value={form.data.brand}
                        onChange={(e) => form.setData('brand', e.target.value)}
                        placeholder="Mini GT"
                        className="w-40"
                    />
                    <datalist id="dex-brand-options">
                        {brands.map((b) => (
                            <option key={b} value={b} />
                        ))}
                    </datalist>
                </div>
                <div className="grid min-w-48 flex-1 gap-1.5">
                    <Label htmlFor="dex-name">Nama dex</Label>
                    <Input
                        id="dex-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Mini GT 2026, Roll Speed Polisi…"
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="dex-year">Tahun (opsional)</Label>
                    <Input
                        id="dex-year"
                        type="number"
                        inputMode="numeric"
                        value={form.data.year}
                        onChange={(e) => form.setData('year', e.target.value)}
                        className="w-28"
                    />
                </div>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? <Loader2 className="animate-spin" /> : <Plus />}
                    Buat dex
                </Button>
            </form>
            <InputError message={form.errors.brand ?? form.errors.name ?? form.errors.year} className="mt-2" />
        </Panel>
    );
}

/** Add entries to an own dex: one by hand, or many from a spreadsheet, a checklist screenshot or the collection. */
export function CustomDexToolbar({
    catalogId,
    brand,
    aiEnabled,
}: {
    catalogId: number;
    brand: string;
    aiEnabled: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);

    return (
        <div className="flex flex-wrap gap-2">
            <Button size="sm" onClick={() => setAdding(true)}>
                <Plus /> Tambah entri
            </Button>
            <Button size="sm" variant="outline" onClick={() => setImporting(true)}>
                <Upload /> Isi banyak sekaligus
            </Button>
            <AddItemDialog open={adding} onOpenChange={setAdding} catalogId={catalogId} />
            <ImportDialog
                open={importing}
                onOpenChange={setImporting}
                catalogId={catalogId}
                brand={brand}
                aiEnabled={aiEnabled}
            />
        </div>
    );
}

function AddItemDialog({
    open,
    onOpenChange,
    catalogId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    catalogId: number;
}) {
    const form = useForm<{
        number: string;
        name: string;
        series: string;
        series_number: string;
        variant_label: string;
        photo: File | null;
    }>({ number: '', name: '', series: '', series_number: '', variant_label: '', photo: null });

    const fields = [
        ['number', 'Nomor / kode', 'MGT00512 (kosongkan = urut otomatis)'],
        ['name', 'Nama model', 'Nissan Skyline GT-R (R34)'],
        ['series', 'Seri', 'Opsional'],
        ['series_number', 'Nomor seri', 'mis. 3/5'],
        ['variant_label', 'Varian / warna', 'mis. Bayside Blue, LHD, Chase'],
    ] as const;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[94dvh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="font-heading">Tambah entri</DialogTitle>
                    <DialogDescription>
                        Satu rilisan. Nomor yang sama dengan varian berbeda jadi varian dari entri itu.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.submit(CustomDexController.addItem(catalogId), {
                            forceFormData: true,
                            preserveScroll: true,
                            // Keep the dialog open for the next one; clear what changes per release.
                            onSuccess: () => form.reset('number', 'name', 'variant_label', 'series_number', 'photo'),
                        });
                    }}
                >
                    {fields.map(([key, label, placeholder]) => (
                        <div key={key} className="grid gap-1.5">
                            <Label htmlFor={`item-${key}`}>{label}</Label>
                            <Input
                                id={`item-${key}`}
                                value={form.data[key]}
                                placeholder={placeholder}
                                onChange={(e) => form.setData(key, e.target.value)}
                            />
                            <InputError message={form.errors[key]} />
                        </div>
                    ))}
                    <div className="grid gap-1.5">
                        <Label htmlFor="item-photo">Foto (opsional)</Label>
                        <Input
                            id="item-photo"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(e) => form.setData('photo', e.target.files?.[0] ?? null)}
                        />
                        <InputError message={form.errors.photo} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Selesai
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <Plus />}
                            Tambah
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ImportDialog({
    open,
    onOpenChange,
    catalogId,
    brand,
    aiEnabled,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    catalogId: number;
    brand: string;
    aiEnabled: boolean;
}) {
    const [busy, setBusy] = useState<'scan' | 'file' | 'collection' | null>(null);
    const screenshots = useRef<HTMLInputElement>(null);
    const sheet = useRef<HTMLInputElement>(null);

    async function scan(files: FileList | null) {
        if (!files?.length) {
            return;
        }

        const body = new FormData();
        Array.from(files).forEach((file) => body.append('screenshots[]', file));
        setBusy('scan');

        try {
            const result = await postJson<{ message: string }>(CustomDexController.scan.url(catalogId), body);
            toast.success(result.message);
            router.reload();
            onOpenChange(false);
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setBusy(null);
    }

    function upload(file: File | undefined) {
        if (!file) {
            return;
        }

        setBusy('file');
        router.post(
            CustomDexController.import.url(catalogId),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => toast.error(errors.file ?? 'Gagal mengimpor.'),
                onFinish: () => setBusy(null),
            },
        );
    }

    function fromCollection() {
        setBusy('collection');
        router.post(
            CustomDexController.fromCollection.url(catalogId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setBusy(null),
            },
        );
    }

    const option = (
        key: typeof busy,
        icon: React.ReactNode,
        title: string,
        text: string,
        action: () => void,
        disabled = false,
    ) => (
        <button
            type="button"
            disabled={busy !== null || disabled}
            onClick={action}
            className="flex w-full items-start gap-3 rounded-lg border p-3 text-left transition hover:border-brand/50 disabled:opacity-60"
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/12 text-brand">
                {busy === key ? <Loader2 className="size-4 animate-spin" /> : icon}
            </span>
            <span>
                <span className="block text-sm font-medium">{title}</span>
                <span className="block text-xs text-muted-foreground">{text}</span>
            </span>
        </button>
    );

    return (
        <Dialog open={open} onOpenChange={(next) => busy === null && onOpenChange(next)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="font-heading">Isi banyak sekaligus</DialogTitle>
                    <DialogDescription>Entri yang sudah ada tidak digandakan.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    {option(
                        'scan',
                        <Sparkles className="size-4" />,
                        'Dari screenshot checklist (AI)',
                        aiEnabled
                            ? 'Screenshot daftar rilisan (post checklist, katalog, pre-order toko), maks. 6. AI yang mengetik daftarnya.'
                            : 'AI belum aktif. Isi API key di Settings → AI Scan.',
                        () => screenshots.current?.click(),
                        !aiEnabled,
                    )}
                    {option(
                        'file',
                        <FileSpreadsheet className="size-4" />,
                        'Dari Excel / CSV',
                        'Baris pertama berisi judul kolom: Nomor, Nama, Seri, Nomor seri, Varian. Minimal kolom Nama.',
                        () => sheet.current?.click(),
                    )}
                    {option(
                        'collection',
                        <Warehouse className="size-4" />,
                        'Dari koleksiku',
                        `Semua diecast ${brand} di koleksimu jadi entri, langsung tertaut (dengan fotonya).`,
                        fromCollection,
                    )}
                </div>
                <input
                    ref={screenshots}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    className="hidden"
                    onChange={(e) => {
                        void scan(e.target.files);
                        e.target.value = '';
                    }}
                />
                <input
                    ref={sheet}
                    type="file"
                    accept=".xlsx,.csv"
                    className="hidden"
                    onChange={(e) => {
                        upload(e.target.files?.[0]);
                        e.target.value = '';
                    }}
                />
                {busy === 'scan' && (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <ImagePlus className="size-3.5" /> AI sedang membaca checklist… bisa sampai satu menit.
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Remove a dex (wiki year or own list); the collection stays, just unlinked. */
export function DeleteCatalogButton({ catalogId, name }: { catalogId: number; name: string }) {
    const [confirming, setConfirming] = useState(false);

    return confirming ? (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            Hapus {name}? Koleksimu tidak ikut terhapus.
            <Button size="sm" variant="destructive" onClick={() => router.delete(DexController.destroy.url(catalogId))}>
                Ya, hapus
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setConfirming(false)}>
                Batal
            </Button>
        </span>
    ) : (
        <Button size="sm" variant="ghost" className="text-muted-foreground" onClick={() => setConfirming(true)}>
            <Trash2 /> Hapus dex
        </Button>
    );
}

/** Remove one release from an own dex. */
export function DeleteItemButton({ itemId, onDeleted }: { itemId: number; onDeleted: () => void }) {
    return (
        <Button
            size="sm"
            variant="ghost"
            className="text-destructive hover:text-destructive"
            onClick={() =>
                router.delete(CustomDexController.destroyItem.url(itemId), {
                    preserveScroll: true,
                    onSuccess: onDeleted,
                })
            }
        >
            <Trash2 /> Hapus entri ini
        </Button>
    );
}
