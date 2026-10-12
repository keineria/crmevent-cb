import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Send, TestTube2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    description: string;
    defaultEmail: string;
    disabled: boolean;
    onSend: (email: string) => void;
}

/** Asks where to send a test of the email being edited. */
export default function TestDialog({ description, defaultEmail, disabled, onSend }: Props) {
    const [open, setOpen] = useState(false);
    const [email, setEmail] = useState(defaultEmail);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" disabled={disabled}>
                    <TestTube2 aria-hidden />
                    Enviar prueba
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Enviar una prueba</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="test-email">Correo</Label>
                    <Input id="test-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        disabled={!email}
                        onClick={() => {
                            setOpen(false);
                            onSend(email);
                        }}
                    >
                        <Send aria-hidden />
                        Enviar prueba
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
