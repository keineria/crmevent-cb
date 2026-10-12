import { Alert, AlertDescription } from '@/components/ui/alert';
import { usePage } from '@inertiajs/react';
import { CircleCheck, CircleX } from 'lucide-react';

/** Success and error messages the server sends back after an action. */
export default function Flash() {
    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;

    return (
        <>
            {flash.success && (
                <Alert role="status" className="border-success/40 bg-success/10">
                    <CircleCheck className="text-success h-4 w-4" aria-hidden />
                    <AlertDescription>{flash.success}</AlertDescription>
                </Alert>
            )}
            {flash.error && (
                <Alert variant="destructive" role="alert">
                    <CircleX className="h-4 w-4" aria-hidden />
                    <AlertDescription>{flash.error}</AlertDescription>
                </Alert>
            )}
        </>
    );
}
