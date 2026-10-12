import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { type ComponentProps, type ReactNode, useState } from 'react';

/** A button that asks before doing something that sends email. */
export default function ConfirmButton({
    title,
    description,
    confirm,
    onConfirm,
    children,
    ...button
}: {
    title: string;
    description: ReactNode;
    confirm: string;
    onConfirm: () => void;
    children: ReactNode;
} & Omit<ComponentProps<typeof Button>, 'onClick'>) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button {...button}>{children}</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancelar</Button>
                    </DialogClose>
                    <Button
                        onClick={() => {
                            setOpen(false);
                            onConfirm();
                        }}
                    >
                        {confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** "60" → "1 h", "90" → "1 h 30 min", "0" → "apenas termina". */
export function delayLabel(minutes: number): string {
    if (minutes <= 0) return 'apenas termina';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return `${[h ? `${h} h` : '', m ? `${m} min` : ''].filter(Boolean).join(' ')} después de terminar`;
}
