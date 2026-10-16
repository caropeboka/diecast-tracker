import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import StylePicker from '@/components/style-picker';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Tampilan" />

            <h1 className="sr-only">Tampilan</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Gaya tampilan"
                    description="Pilih gaya yang paling kamu suka — langsung berubah di seluruh aplikasi."
                />
                <StylePicker />
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Mode"
                    description="Ketiga gaya dirancang untuk mode gelap."
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Tampilan',
            href: editAppearance(),
        },
    ],
};
