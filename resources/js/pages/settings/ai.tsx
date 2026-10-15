import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, ExternalLink, Loader2, PlugZap } from 'lucide-react';
import { useState } from 'react';
import AiController from '@/actions/App/Http/Controllers/Settings/AiController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/ai';

type Provider = 'gemini' | 'anthropic';

type ProviderInfo = {
    model: string;
    key_hint: string | null;
    key_source: 'settings' | 'env' | null;
};

const META: Record<
    Provider,
    { name: string; description: string; keyUrl: string; placeholder: string }
> = {
    gemini: {
        name: 'Google Gemini',
        description: 'Ada kuota gratis harian. Cocok untuk mulai.',
        keyUrl: 'https://aistudio.google.com/apikey',
        placeholder: 'AIza…',
    },
    anthropic: {
        name: 'Claude (Anthropic)',
        description: 'Berbayar per pemakaian. Biasanya lebih akurat.',
        keyUrl: 'https://platform.claude.com/settings/keys',
        placeholder: 'sk-ant-…',
    },
};

type TestState =
    | { state: 'idle' }
    | { state: 'testing' }
    | { state: 'ok'; models: string[] }
    | { state: 'failed'; message: string };

export default function AiSettings({
    provider,
    providers,
}: {
    provider: Provider;
    providers: Record<Provider, ProviderInfo>;
}) {
    const form = useForm({
        provider,
        gemini_model: providers.gemini.model,
        anthropic_model: providers.anthropic.model,
        gemini_api_key: '',
        anthropic_api_key: '',
        remove_keys: [] as Provider[],
    });
    const { data, setData, errors, processing } = form;

    const [tests, setTests] = useState<Record<Provider, TestState>>({
        gemini: { state: 'idle' },
        anthropic: { state: 'idle' },
    });

    async function testKey(target: Provider) {
        setTests((current) => ({ ...current, [target]: { state: 'testing' } }));

        try {
            const { models } = await postJson<{ models: string[] }>(
                AiController.models.url(),
                { provider: target, api_key: data[`${target}_api_key`] },
            );
            setTests((current) => ({
                ...current,
                [target]: { state: 'ok', models },
            }));
        } catch (error) {
            setTests((current) => ({
                ...current,
                [target]: { state: 'failed', message: (error as Error).message },
            }));
        }
    }

    function toggleRemove(target: Provider) {
        setData(
            'remove_keys',
            data.remove_keys.includes(target)
                ? data.remove_keys.filter((p) => p !== target)
                : [...data.remove_keys, target],
        );
    }

    return (
        <>
            <Head title="Pengaturan AI" />

            <h1 className="sr-only">Pengaturan AI</h1>

            <form
                className="space-y-8"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.submit(AiController.update(), {
                        preserveScroll: true,
                        onSuccess: () =>
                            form.setData((current) => ({
                                ...current,
                                gemini_api_key: '',
                                anthropic_api_key: '',
                                remove_keys: [],
                            })),
                    });
                }}
            >
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="AI untuk scan foto"
                        description="Pilih AI yang membaca foto diecast dan mengisi field otomatis."
                    />

                    <div className="grid gap-3 sm:grid-cols-2">
                        {(Object.keys(META) as Provider[]).map((p) => (
                            <button
                                key={p}
                                type="button"
                                onClick={() => setData('provider', p)}
                                className={cn(
                                    'rounded-xl border p-4 text-left transition',
                                    data.provider === p
                                        ? 'border-primary ring-2 ring-primary/20'
                                        : 'border-sidebar-border/70 hover:bg-muted/50 dark:border-sidebar-border',
                                )}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {META[p].name}
                                    </span>
                                    {data.provider === p && (
                                        <CheckCircle2 className="size-4 text-primary" />
                                    )}
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {META[p].description}
                                </p>
                                <p className="mt-2 text-xs">
                                    {providers[p].key_hint ? (
                                        <span className="text-emerald-600 dark:text-emerald-400">
                                            API key terpasang
                                        </span>
                                    ) : (
                                        <span className="text-amber-600 dark:text-amber-400">
                                            Belum ada API key
                                        </span>
                                    )}
                                </p>
                            </button>
                        ))}
                    </div>
                    <InputError message={errors.provider} />
                </div>

                {(Object.keys(META) as Provider[]).map((p) => {
                    const info = providers[p];
                    const test = tests[p];
                    const keyField = `${p}_api_key` as const;
                    const modelField = `${p}_model` as const;
                    const removing = data.remove_keys.includes(p);

                    return (
                        <div
                            key={p}
                            className={cn(
                                'space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border',
                                data.provider !== p && 'opacity-70',
                            )}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <h3 className="font-medium">{META[p].name}</h3>
                                {data.provider === p && <Badge>Aktif</Badge>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={keyField}>API key</Label>
                                {info.key_hint && (
                                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <span
                                            className={cn(
                                                'font-mono',
                                                removing && 'line-through',
                                            )}
                                        >
                                            {info.key_hint}
                                        </span>
                                        <span>
                                            (
                                            {info.key_source === 'env'
                                                ? 'dari file .env'
                                                : 'tersimpan'}
                                            )
                                        </span>
                                        {info.key_source === 'settings' && (
                                            <button
                                                type="button"
                                                onClick={() => toggleRemove(p)}
                                                className="text-destructive underline-offset-4 hover:underline"
                                            >
                                                {removing
                                                    ? 'Batal hapus'
                                                    : 'Hapus key'}
                                            </button>
                                        )}
                                    </div>
                                )}
                                <div className="flex gap-2">
                                    <Input
                                        id={keyField}
                                        type="password"
                                        autoComplete="off"
                                        placeholder={
                                            info.key_hint
                                                ? 'Kosongkan untuk tetap pakai key sekarang'
                                                : META[p].placeholder
                                        }
                                        value={data[keyField]}
                                        onChange={(e) =>
                                            setData(keyField, e.target.value)
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => void testKey(p)}
                                        disabled={
                                            test.state === 'testing' ||
                                            (!data[keyField] && !info.key_hint)
                                        }
                                    >
                                        {test.state === 'testing' ? (
                                            <Loader2 className="animate-spin" />
                                        ) : (
                                            <PlugZap />
                                        )}
                                        Tes
                                    </Button>
                                </div>
                                <a
                                    href={META[p].keyUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                >
                                    Buat API key <ExternalLink className="size-3" />
                                </a>
                                <InputError message={errors[keyField]} />

                                {test.state === 'failed' && (
                                    <p className="text-sm text-destructive">
                                        {test.message}
                                    </p>
                                )}
                                {test.state === 'ok' && (
                                    <p className="text-sm text-emerald-600 dark:text-emerald-400">
                                        Key valid — {test.models.length} model
                                        tersedia. Pilih di bawah.
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={modelField}>Model</Label>
                                {test.state === 'ok' &&
                                test.models.length > 0 ? (
                                    <Select
                                        value={data[modelField]}
                                        onValueChange={(v) =>
                                            setData(modelField, v)
                                        }
                                    >
                                        <SelectTrigger
                                            id={modelField}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Array.from(
                                                new Set([
                                                    data[modelField],
                                                    ...test.models,
                                                ]),
                                            ).map((model) => (
                                                <SelectItem
                                                    key={model}
                                                    value={model}
                                                >
                                                    {model}
                                                    {!test.models.includes(
                                                        model,
                                                    ) && ' (tidak tersedia)'}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input
                                        id={modelField}
                                        value={data[modelField]}
                                        onChange={(e) =>
                                            setData(modelField, e.target.value)
                                        }
                                    />
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Klik <strong>Tes</strong> untuk melihat
                                    daftar model yang bisa dipakai key ini.
                                    {p === 'gemini' &&
                                        ' Untuk tier gratis, pilih model "flash".'}
                                </p>
                                <InputError message={errors[modelField]} />
                            </div>
                        </div>
                    );
                })}

                <Button disabled={processing}>
                    {processing && <Loader2 className="animate-spin" />}
                    Simpan pengaturan
                </Button>
            </form>
        </>
    );
}

AiSettings.layout = {
    breadcrumbs: [
        {
            title: 'Pengaturan AI',
            href: edit(),
        },
    ],
};
