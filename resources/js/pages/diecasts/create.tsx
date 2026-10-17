import { Head } from '@inertiajs/react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import DiecastForm from '@/components/diecast-form';
import Heading from '@/components/heading';
import type {
    DiecastStatus,
    DiecastSuggestions,
    ScanItemPrefill,
} from '@/types/diecast';
import type { DexPrefill } from '@/types/dex';

export default function CreateDiecast({
    suggestions,
    scanEnabled,
    status,
    scanItem,
    dexItem,
}: {
    suggestions: DiecastSuggestions;
    scanEnabled: boolean;
    status: DiecastStatus;
    scanItem: ScanItemPrefill | null;
    dexItem: DexPrefill | null;
}) {
    const returnTo = new URLSearchParams(window.location.search).get('return');

    return (
        <>
            <Head title="Tambah diecast" />
            <div className="p-4 md:p-6">
                <Heading
                    title="Tambah diecast"
                    description={
                        scanItem
                            ? 'Data dari hasil scan. Periksa, lengkapi, lalu simpan.'
                            : dexItem
                              ? `Data dari Dex (${dexItem.catalog} #${dexItem.number}). Tambahkan fotomu sendiri, lalu simpan.`
                              : scanEnabled
                              ? 'Foto diecast-nya, biar AI yang isi datanya. Kamu tinggal cek dan simpan.'
                              : 'Isi data diecast kamu.'
                    }
                />
                <DiecastForm
                    key={
                        scanItem
                            ? `scan-${scanItem.id}`
                            : dexItem
                              ? `dex-${dexItem.item_id}-${status}`
                              : 'new'
                    }
                    suggestions={suggestions}
                    scanEnabled={scanEnabled}
                    status={status}
                    scanItem={scanItem}
                    dexItem={dexItem}
                    returnTo={returnTo ?? undefined}
                />
            </div>
        </>
    );
}

CreateDiecast.layout = {
    breadcrumbs: [
        { title: 'Koleksi', href: DiecastController.index() },
        { title: 'Tambah', href: DiecastController.create() },
    ],
};
