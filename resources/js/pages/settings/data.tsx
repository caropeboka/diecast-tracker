import { Head, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
} from 'lucide-react';
import DataController from '@/actions/App/Http/Controllers/Settings/DataController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/data';

type ImportResult = {
    type: 'import' | 'restore';
    imported: number;
    skipped: string[];
    skipped_total: number;
};

const card =
    'space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border';

const fileInput =
    'block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-secondary/80';

export default function DataSettings({
    counts,
    zipAvailable,
    result,
}: {
    counts: { diecasts: number; photos: number };
    zipAvailable: boolean;
    result: ImportResult | null;
}) {
    const { errors: pageErrors } = usePage<{ errors: Record<string, string> }>()
        .props;

    const importForm = useForm<{ file: File | null }>({ file: null });
    const restoreForm = useForm<{
        backup: File | null;
        mode: 'merge' | 'replace';
        confirm: boolean;
    }>({ backup: null, mode: 'merge', confirm: false });

    return (
        <>
            <Head title="Backup & Data" />

            <h1 className="sr-only">Backup & Data</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Backup & Data"
                    description={`Koleksimu: ${counts.diecasts} diecast, ${counts.photos} foto.`}
                />

                {!zipAvailable && (
                    <div className="flex gap-2 rounded-lg border border-amber-400/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            Ekstensi PHP <strong>zip</strong> belum aktif, jadi
                            Excel dan backup belum bisa dibuat. Aktifkan lewat
                            Laragon: klik kanan ikon Laragon → <em>PHP</em> →{' '}
                            <em>Extensions</em> → centang <em>zip</em>.
                            Import file .csv tetap bisa.
                        </div>
                    </div>
                )}

                {pageErrors.backup && (
                    <p className="text-sm text-destructive">
                        {pageErrors.backup}
                    </p>
                )}

                {result && (
                    <div
                        className={cn(
                            'rounded-lg border p-3 text-sm',
                            result.skipped_total
                                ? 'border-amber-400/50 bg-amber-50 dark:bg-amber-500/10'
                                : 'border-emerald-400/50 bg-emerald-50 dark:bg-emerald-500/10',
                        )}
                    >
                        <p className="font-medium">
                            {result.imported} diecast berhasil{' '}
                            {result.type === 'restore'
                                ? 'dipulihkan'
                                : 'diimport'}
                            {result.skipped_total > 0 &&
                                `, ${result.skipped_total} dilewati:`}
                        </p>
                        {result.skipped.length > 0 && (
                            <ul className="mt-2 list-disc space-y-0.5 pl-5 text-muted-foreground">
                                {result.skipped.map((reason) => (
                                    <li key={reason}>{reason}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {/* Backup */}
                <section className={card}>
                    <h3 className="flex items-center gap-2 font-medium">
                        <Archive className="size-4" /> Backup lengkap
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        Satu file ZIP berisi semua data <em>dan</em> foto.
                        Simpan di Google Drive / flashdisk secara berkala.
                    </p>
                    <Button asChild={zipAvailable} disabled={!zipAvailable}>
                        {zipAvailable ? (
                            <a href={DataController.backup.url()}>
                                <Download /> Download backup (.zip)
                            </a>
                        ) : (
                            <span>
                                <Download /> Download backup (.zip)
                            </span>
                        )}
                    </Button>
                </section>

                {/* Excel */}
                <section className={card}>
                    <h3 className="flex items-center gap-2 font-medium">
                        <FileSpreadsheet className="size-4" /> Excel
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        Export untuk dibuka di Excel / Google Sheets, atau
                        import catatan koleksi lama kamu (tanpa foto).
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            asChild={zipAvailable}
                            disabled={!zipAvailable}
                        >
                            {zipAvailable ? (
                                <a href={DataController.export.url()}>
                                    <Download /> Export koleksi (.xlsx)
                                </a>
                            ) : (
                                <span>
                                    <Download /> Export koleksi (.xlsx)
                                </span>
                            )}
                        </Button>
                        {zipAvailable && (
                            <Button variant="ghost" asChild>
                                <a
                                    href={DataController.export.url({
                                        query: { template: 1 },
                                    })}
                                >
                                    Download template kosong
                                </a>
                            </Button>
                        )}
                    </div>

                    <form
                        className="space-y-2 border-t pt-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            importForm.submit(DataController.import(), {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => importForm.reset(),
                            });
                        }}
                    >
                        <Label htmlFor="import-file">
                            Import dari Excel (.xlsx) atau CSV
                        </Label>
                        <input
                            id="import-file"
                            type="file"
                            accept=".xlsx,.csv"
                            className={fileInput}
                            onChange={(e) =>
                                importForm.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Baris pertama harus judul kolom seperti di template
                            (minimal kolom <strong>Nama</strong>). Data
                            ditambahkan ke koleksi, tidak mengganti.
                        </p>
                        <InputError message={importForm.errors.file} />
                        <Button
                            size="sm"
                            disabled={!importForm.data.file || importForm.processing}
                        >
                            {importForm.processing ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <Upload />
                            )}
                            Import
                        </Button>
                    </form>
                </section>

                {/* Restore */}
                <section className={card}>
                    <h3 className="flex items-center gap-2 font-medium">
                        <Upload className="size-4" /> Pulihkan dari backup
                    </h3>
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            restoreForm.submit(DataController.restore(), {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => restoreForm.reset(),
                            });
                        }}
                    >
                        <input
                            type="file"
                            accept=".zip"
                            className={fileInput}
                            aria-label="File backup"
                            onChange={(e) =>
                                restoreForm.setData(
                                    'backup',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <InputError message={restoreForm.errors.backup} />

                        <div className="grid gap-2 text-sm">
                            {(
                                [
                                    [
                                        'merge',
                                        'Tambahkan ke koleksi sekarang',
                                    ],
                                    [
                                        'replace',
                                        'Ganti seluruh koleksi sekarang dengan isi backup',
                                    ],
                                ] as const
                            ).map(([mode, label]) => (
                                <label
                                    key={mode}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        checked={restoreForm.data.mode === mode}
                                        onChange={() =>
                                            restoreForm.setData('mode', mode)
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>

                        {restoreForm.data.mode === 'replace' && (
                            <label className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
                                <Checkbox
                                    checked={restoreForm.data.confirm}
                                    onCheckedChange={(v) =>
                                        restoreForm.setData('confirm', v === true)
                                    }
                                    className="mt-0.5"
                                />
                                Saya mengerti {counts.diecasts} diecast yang
                                ada sekarang (beserta fotonya) akan dihapus dan
                                diganti isi backup.
                            </label>
                        )}
                        <InputError message={restoreForm.errors.confirm} />

                        <Button
                            size="sm"
                            variant={
                                restoreForm.data.mode === 'replace'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={
                                !zipAvailable ||
                                !restoreForm.data.backup ||
                                restoreForm.processing
                            }
                        >
                            {restoreForm.processing && (
                                <Loader2 className="animate-spin" />
                            )}
                            Pulihkan
                        </Button>
                    </form>
                </section>
            </div>
        </>
    );
}

DataSettings.layout = {
    breadcrumbs: [{ title: 'Backup & Data', href: edit() }],
};
