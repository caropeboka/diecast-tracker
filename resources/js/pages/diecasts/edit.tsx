import { Head } from '@inertiajs/react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import DiecastForm from '@/components/diecast-form';
import Heading from '@/components/heading';
import type {
    Diecast,
    DiecastSuggestions,
    PriceCheck,
} from '@/types/diecast';
import type { DexLinkState } from '@/types/dex';

export default function EditDiecast({
    diecast,
    suggestions,
    scanEnabled,
    priceChecks,
    dex,
}: {
    diecast: Diecast;
    suggestions: DiecastSuggestions;
    scanEnabled: boolean;
    priceChecks: PriceCheck[];
    dex: DexLinkState;
}) {
    return (
        <>
            <Head title={diecast.name} />
            <div className="p-4 md:p-6">
                <Heading
                    title={diecast.name}
                    description={[diecast.brand, diecast.series]
                        .filter(Boolean)
                        .join(' · ')}
                />
                <DiecastForm
                    key={diecast.id}
                    diecast={diecast}
                    suggestions={suggestions}
                    scanEnabled={scanEnabled}
                    priceChecks={priceChecks}
                    dex={dex}
                />
            </div>
        </>
    );
}

EditDiecast.layout = {
    breadcrumbs: [
        { title: 'Koleksi', href: DiecastController.index() },
        { title: 'Edit', href: '#' },
    ],
};
