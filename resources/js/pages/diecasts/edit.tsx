import { Head } from '@inertiajs/react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import DiecastForm from '@/components/diecast-form';
import Heading from '@/components/heading';
import type {
    Diecast,
    DiecastSuggestions,
    PriceCheck,
} from '@/types/diecast';

export default function EditDiecast({
    diecast,
    suggestions,
    scanEnabled,
    priceChecks,
}: {
    diecast: Diecast;
    suggestions: DiecastSuggestions;
    scanEnabled: boolean;
    priceChecks: PriceCheck[];
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
