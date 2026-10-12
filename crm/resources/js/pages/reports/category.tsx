import Heading from '@/components/heading';
import FilterBar from '@/components/reports/filter-bar';
import PeopleDialog from '@/components/reports/people-dialog';
import ReportSection from '@/components/reports/report-section';
import { reportQuery, type ReportCard, type ReportFilters } from '@/components/reports/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';

interface PageProps {
    filters: ReportFilters;
    editions: number[];
    category: { key: string; title: string };
    sections: { key: string; title: string }[];
    today: string;
    [key: string]: unknown;
}

export default function ReportCategory() {
    const { filters, editions, category, sections, today } = usePage<PageProps>().props;
    const [picked, setPicked] = useState<{ section: string; card: string; label: string } | null>(null);
    const back = `/reports?${reportQuery(filters)}`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Reportes', href: back },
        { title: category.title, href: `/reports/categoria/${category.key}?${reportQuery(filters)}` },
    ];
    // Sections remount (and reload) whenever a filter changes.
    const filterKey = reportQuery(filters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${category.title} · Reportes`} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title={`${category.title} · feria ${filters.anio}`} description="Haz clic en cualquier tarjeta para ver las personas." />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={back}>
                            <ArrowLeft className="size-4" />
                            Volver
                        </Link>
                    </Button>
                </div>

                <FilterBar path={`/reports/categoria/${category.key}`} filters={filters} editions={editions} today={today} />

                <div className="space-y-3" key={filterKey}>
                    {sections.map((s) => (
                        <ReportSection
                            key={s.key}
                            sectionKey={s.key}
                            title={s.title}
                            filters={filters}
                            defaultOpen
                            onPick={(card: ReportCard) => setPicked({ section: s.key, card: card.key, label: `${s.title}: ${card.label}` })}
                        />
                    ))}
                </div>
            </div>

            <PeopleDialog filters={filters} target={picked} onClose={() => setPicked(null)} />
        </AppLayout>
    );
}
