import Flash from '@/components/campaigns/flash';
import { selectClass } from '@/components/finance/period-filter';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { delayLabel } from '@/components/surveys/confirm-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, LoaderCircle, Plus, RotateCcw, Save, Trash2 } from 'lucide-react';
import { type FormEventHandler } from 'react';

type QuestionType = 'rating' | 'nps' | 'choice' | 'text';

interface Question {
    key?: string;
    type: QuestionType;
    label: string;
    required: boolean;
    options?: string[];
}

interface FormData {
    title: string;
    intro: string;
    enabled: boolean;
    delay_minutes: number;
    require_check_in: boolean;
    questions: Question[];
}

interface PageProps {
    form: Omit<FormData, 'intro'> & { type: string; label: string; intro: string | null; updated_at: string | null };
    defaults: Question[];
    questionTypes: Record<QuestionType, string>;
    [key: string]: unknown;
}

const DELAYS = [0, 30, 60, 120, 180, 360, 720, 1440];

export default function SurveyFormEditor() {
    const { form, defaults, questionTypes } = usePage<PageProps>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Encuestas', href: '/encuestas' },
        { title: form.label, href: `/encuestas/preguntas/${form.type}` },
    ];

    const { data, setData, put, processing, errors, isDirty } = useForm<FormData>({
        title: form.title,
        intro: form.intro ?? '',
        enabled: form.enabled,
        delay_minutes: form.delay_minutes,
        require_check_in: form.require_check_in,
        questions: form.questions,
    });
    const err = errors as Record<string, string | undefined>;

    const setQuestion = (i: number, patch: Partial<Question>) =>
        setData(
            'questions',
            data.questions.map((q, n) => (n === i ? { ...q, ...patch } : q)),
        );
    const move = (i: number, by: number) => {
        const list = [...data.questions];
        const [q] = list.splice(i, 1);
        list.splice(i + by, 0, q);
        setData('questions', list);
    };
    const add = () => setData('questions', [...data.questions, { type: 'rating', label: '', required: false }]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/encuestas/preguntas/${form.type}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Encuesta de ${form.label.toLowerCase()}`} />

            <form onSubmit={submit} className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Encuesta de ${form.label.toLowerCase()}`}
                        description={`La reciben al terminar cada actividad de este tipo. Cambiar las preguntas no afecta encuestas ya enviadas.${
                            form.updated_at ? ` Editada ${dateTime.format(new Date(form.updated_at))}.` : ''
                        }`}
                    />
                    <Button type="submit" disabled={processing}>
                        {processing ? <LoaderCircle className="animate-spin" aria-hidden /> : <Save aria-hidden />}
                        {isDirty ? 'Guardar' : 'Guardado'}
                    </Button>
                </div>

                <Flash />

                <section className="grid gap-4 rounded-xl border p-4 lg:grid-cols-2" aria-label="Envío">
                    <label className="flex items-start gap-3 text-sm">
                        <Checkbox checked={data.enabled} onCheckedChange={(v) => setData('enabled', v === true)} className="mt-0.5" />
                        <span>
                            <span className="font-medium">Enviar sola al terminar cada actividad</span>
                            <span className="text-muted-foreground block">
                                Al encenderla solo cuenta para actividades que terminen desde ese momento. Las que ya pasaron se envían a mano.
                            </span>
                        </span>
                    </label>
                    <label className="flex items-start gap-3 text-sm">
                        <Checkbox
                            checked={data.require_check_in}
                            onCheckedChange={(v) => setData('require_check_in', v === true)}
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium">Solo para quienes hicieron check-in</span>
                            <span className="text-muted-foreground block">Si lo desmarcas, le llega a todo el que pagó aunque no haya llegado.</span>
                        </span>
                    </label>
                    <div className="grid gap-2 sm:max-w-xs">
                        <Label htmlFor="delay">Cuándo sale</Label>
                        <select
                            id="delay"
                            className={selectClass}
                            value={data.delay_minutes}
                            onChange={(e) => setData('delay_minutes', Number(e.target.value))}
                        >
                            {[...new Set([...DELAYS, data.delay_minutes])]
                                .sort((a, b) => a - b)
                                .map((m) => (
                                    <option key={m} value={m}>
                                        {delayLabel(m).replace(/^./, (c) => c.toUpperCase())}
                                    </option>
                                ))}
                        </select>
                        <p className="text-muted-foreground text-xs">
                            Se cuenta desde la hora de fin de la actividad en el plugin (o las 6 p. m. si no tiene).
                        </p>
                        <InputError message={errors.delay_minutes} />
                    </div>
                </section>

                <section className="grid gap-4 rounded-xl border p-4" aria-label="Encabezado">
                    <div className="grid gap-2">
                        <Label htmlFor="title">Título</Label>
                        <Input id="title" value={data.title} maxLength={120} onChange={(e) => setData('title', e.target.value)} />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="intro">Texto de entrada (opcional)</Label>
                        <Textarea id="intro" rows={2} maxLength={500} value={data.intro} onChange={(e) => setData('intro', e.target.value)} />
                        <InputError message={errors.intro} />
                    </div>
                </section>

                <section className="space-y-3" aria-labelledby="questions-heading">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="questions-heading" className="text-base font-semibold">
                            Preguntas
                        </h2>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setData('questions', defaults)}>
                            <RotateCcw aria-hidden />
                            Restaurar las sugeridas
                        </Button>
                    </div>
                    <InputError message={errors.questions} />

                    <ol className="space-y-3">
                        {data.questions.map((q, i) => (
                            <li key={q.key ?? `nueva-${i}`} className="grid gap-3 rounded-xl border p-4">
                                <div className="grid gap-3 sm:grid-cols-[1fr_14rem]">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`q-${i}`}>Pregunta {i + 1}</Label>
                                        <Input
                                            id={`q-${i}`}
                                            value={q.label}
                                            maxLength={200}
                                            onChange={(e) => setQuestion(i, { label: e.target.value })}
                                        />
                                        <InputError message={err[`questions.${i}.label`]} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`qt-${i}`}>Tipo</Label>
                                        <select
                                            id={`qt-${i}`}
                                            className={selectClass}
                                            value={q.type}
                                            onChange={(e) => {
                                                const type = e.target.value as QuestionType;
                                                setQuestion(i, { type, options: type === 'choice' ? (q.options ?? ['', '']) : undefined });
                                            }}
                                        >
                                            {Object.entries(questionTypes).map(([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                {q.type === 'choice' && (
                                    <div className="grid gap-2">
                                        <span className="text-sm font-medium">Opciones</span>
                                        {(q.options ?? []).map((o, n) => (
                                            <div key={n} className="flex gap-2">
                                                <Input
                                                    aria-label={`Opción ${n + 1}`}
                                                    value={o}
                                                    maxLength={80}
                                                    onChange={(e) =>
                                                        setQuestion(i, { options: (q.options ?? []).map((x, m) => (m === n ? e.target.value : x)) })
                                                    }
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Quitar opción ${n + 1}`}
                                                    onClick={() => setQuestion(i, { options: (q.options ?? []).filter((_, m) => m !== n) })}
                                                >
                                                    <Trash2 aria-hidden />
                                                </Button>
                                            </div>
                                        ))}
                                        {(q.options ?? []).length < 10 && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="justify-self-start"
                                                onClick={() => setQuestion(i, { options: [...(q.options ?? []), ''] })}
                                            >
                                                <Plus aria-hidden />
                                                Opción
                                            </Button>
                                        )}
                                        <InputError message={err[`questions.${i}.options`]} />
                                    </div>
                                )}

                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={q.required} onCheckedChange={(v) => setQuestion(i, { required: v === true })} />
                                        Obligatoria
                                    </label>
                                    <div className="flex gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Subir"
                                            disabled={i === 0}
                                            onClick={() => move(i, -1)}
                                        >
                                            <ArrowUp aria-hidden />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Bajar"
                                            disabled={i === data.questions.length - 1}
                                            onClick={() => move(i, 1)}
                                        >
                                            <ArrowDown aria-hidden />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Quitar pregunta ${i + 1}`}
                                            disabled={data.questions.length === 1}
                                            onClick={() =>
                                                setData(
                                                    'questions',
                                                    data.questions.filter((_, n) => n !== i),
                                                )
                                            }
                                        >
                                            <Trash2 aria-hidden />
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>

                    {data.questions.length < 20 && (
                        <Button type="button" variant="outline" onClick={add}>
                            <Plus aria-hidden />
                            Agregar pregunta
                        </Button>
                    )}
                    <p className="text-muted-foreground text-xs">
                        Las calificaciones de 1 a 5 forman la satisfacción promedio y la de 0 a 10, el NPS (recomendación) en Reportes.
                    </p>
                </section>
            </form>
        </AppLayout>
    );
}
