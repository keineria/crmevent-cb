import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { day, money } from '@/lib/format';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useEffect } from 'react';
import { selectClass } from './period-filter';

export interface ExpenseRow {
    id: number;
    edition_year: number;
    activity_id: number | null;
    activity: string | null;
    category: string;
    concept: string;
    mode: 'fixed' | 'per_person';
    unit_amount: number;
    quantity: number;
    people: number | null;
    amount: number;
    supplier: string | null;
    spent_on: string;
    paid: boolean;
    notes: string | null;
    creator: string | null;
}

export interface ActivityOption {
    id: number;
    name: string;
    type: string;
    date: string | null;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    expense?: ExpenseRow | null;
    /** Activity to preselect for a new cost. */
    activityId?: number | null;
    edition: number;
    editions: number[];
    activities: ActivityOption[];
    categories: Record<string, string>;
    modes: Record<string, string>;
    types: Record<string, string>;
    today: string;
}

/** Creates or edits one cost. A cost without an activity is one of the fair's other expenses. */
export default function ExpenseDialog({
    open,
    onOpenChange,
    expense,
    activityId,
    edition,
    editions,
    activities,
    categories,
    modes,
    types,
    today,
}: Props) {
    const blank = () => ({
        edition_year: expense?.edition_year ?? edition,
        evr_activity_id: (expense ? expense.activity_id : (activityId ?? null)) as number | null,
        category: expense?.category ?? (activityId ? 'transporte' : 'logistica'),
        concept: expense?.concept ?? '',
        mode: expense?.mode ?? ('fixed' as 'fixed' | 'per_person'),
        unit_amount: expense ? String(expense.unit_amount) : '',
        quantity: expense ? String(expense.quantity) : '1',
        supplier: expense?.supplier ?? '',
        spent_on: expense?.spent_on ?? today,
        paid: expense?.paid ?? false,
        notes: expense?.notes ?? '',
    });
    const { data, setData, post, put, processing, errors, reset, clearErrors, setDefaults } = useForm(blank());

    useEffect(() => {
        if (open) {
            const values = blank();
            setDefaults(values);
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, expense?.id, activityId]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (expense) put(`/finance/expenses/${expense.id}`, options);
        else post('/finance/expenses', options);
    };

    const fixedTotal = Number(data.unit_amount || 0) * Number(data.quantity || 0);
    const linked = activities.find((a) => a.id === data.evr_activity_id);
    const missingActivity = expense?.activity_id && !linked ? expense : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{expense ? 'Editar gasto' : 'Nuevo gasto'}</DialogTitle>
                    <DialogDescription>Asócialo a una actividad para ver su utilidad, o déjalo como gasto general de la feria.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <div className="grid gap-2">
                            <Label htmlFor="exp-activity">Actividad</Label>
                            <select
                                id="exp-activity"
                                className={selectClass}
                                value={data.evr_activity_id ?? ''}
                                onChange={(e) => setData('evr_activity_id', e.target.value ? Number(e.target.value) : null)}
                            >
                                <option value="">Gasto general (otros gastos)</option>
                                {missingActivity && (
                                    <option value={missingActivity.activity_id!}>
                                        {missingActivity.activity ?? `Actividad #${missingActivity.activity_id}`}
                                    </option>
                                )}
                                {Object.entries(types).map(([type, label]) => {
                                    const options = activities.filter((a) => a.type === type);
                                    return options.length ? (
                                        <optgroup key={type} label={label}>
                                            {options.map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.date ? `${day(a.date)} · ` : ''}
                                                    {a.name}
                                                </option>
                                            ))}
                                        </optgroup>
                                    ) : null;
                                })}
                            </select>
                            <InputError message={errors.evr_activity_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="exp-edition">Edición</Label>
                            <select
                                id="exp-edition"
                                className={selectClass}
                                value={data.edition_year}
                                onChange={(e) => setData('edition_year', Number(e.target.value))}
                            >
                                {editions.map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="exp-concept">Concepto</Label>
                            <Input
                                id="exp-concept"
                                required
                                maxLength={191}
                                placeholder="Bus 19 pasajeros"
                                value={data.concept}
                                onChange={(e) => setData('concept', e.target.value)}
                            />
                            <InputError message={errors.concept} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="exp-category">Categoría</Label>
                            <select
                                id="exp-category"
                                className={selectClass}
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                            >
                                {Object.entries(categories).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.category} />
                        </div>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">Cómo se calcula</legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {Object.entries(modes).map(([key, label]) => (
                                <label
                                    key={key}
                                    className="has-checked:border-primary has-checked:bg-primary/5 flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        className="mt-1"
                                        checked={data.mode === key}
                                        onChange={() => setData('mode', key as 'fixed' | 'per_person')}
                                    />
                                    <span>
                                        <span className="font-medium">{label}</span>
                                        <span className="text-muted-foreground block text-xs">
                                            {key === 'fixed'
                                                ? 'Valor × cantidad, por ejemplo el bus o los guías.'
                                                : linked
                                                  ? 'Valor × inscritos pagos de la actividad, por ejemplo el almuerzo.'
                                                  : 'Valor × inscritos pagos de la feria, por ejemplo la camiseta.'}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="exp-unit">{data.mode === 'per_person' ? 'Valor por inscrito' : 'Valor unitario'}</Label>
                            <Input
                                id="exp-unit"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="any"
                                required
                                value={data.unit_amount}
                                onChange={(e) => setData('unit_amount', e.target.value)}
                            />
                            <InputError message={errors.unit_amount} />
                        </div>
                        {data.mode === 'fixed' && (
                            <div className="grid gap-2">
                                <Label htmlFor="exp-qty">Cantidad</Label>
                                <Input
                                    id="exp-qty"
                                    type="number"
                                    inputMode="decimal"
                                    min={0.01}
                                    step="any"
                                    required
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', e.target.value)}
                                />
                                <InputError message={errors.quantity} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="exp-date">Fecha del gasto</Label>
                            <Input id="exp-date" type="date" required value={data.spent_on} onChange={(e) => setData('spent_on', e.target.value)} />
                            <InputError message={errors.spent_on} />
                        </div>
                    </div>
                    {data.mode === 'fixed' && fixedTotal > 0 && (
                        <p className="text-muted-foreground -mt-2 text-sm">Total: {money.format(fixedTotal)}</p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="exp-supplier">Proveedor (opcional)</Label>
                        <Input id="exp-supplier" maxLength={191} value={data.supplier} onChange={(e) => setData('supplier', e.target.value)} />
                        <InputError message={errors.supplier} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="exp-notes">Notas (opcional)</Label>
                        <Textarea id="exp-notes" rows={2} maxLength={2000} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        <InputError message={errors.notes} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.paid} onCheckedChange={(v) => setData('paid', v === true)} />
                        Ya está pagado
                    </label>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            {expense ? 'Guardar cambios' : 'Guardar gasto'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
