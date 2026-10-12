import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import QrScanner from 'qr-scanner';
import { useEffect, useRef, useState } from 'react';

/**
 * Opens the device camera and reports the first QR it reads. Needs HTTPS and camera permission.
 */
export default function QrScanDialog({
    open,
    onOpenChange,
    onScan,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onScan: (text: string) => void;
}) {
    const video = useRef<HTMLVideoElement>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setError(null);
        let scanner: QrScanner | null = null;
        let done = false;

        // The video element mounts with the dialog; wait a frame for it.
        const frame = requestAnimationFrame(() => {
            if (!video.current) return;
            scanner = new QrScanner(
                video.current,
                (result) => {
                    if (done) return;
                    done = true;
                    navigator.vibrate?.(80);
                    onScan(result.data);
                    onOpenChange(false);
                },
                { preferredCamera: 'environment', highlightScanRegion: true, highlightCodeOutline: true, maxScansPerSecond: 10 },
            );
            scanner.start().catch(() => setError('No se pudo abrir la cámara. Revisa el permiso del navegador o escribe el documento.'));
        });

        return () => {
            cancelAnimationFrame(frame);
            scanner?.stop();
            scanner?.destroy();
        };
    }, [open, onScan, onOpenChange]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Escanear código QR</DialogTitle>
                    <DialogDescription>Apunta la cámara al código del participante.</DialogDescription>
                </DialogHeader>
                {error ? (
                    <p className="text-destructive text-sm">{error}</p>
                ) : (
                    <video ref={video} className="aspect-square w-full rounded-lg bg-black object-cover" muted playsInline />
                )}
            </DialogContent>
        </Dialog>
    );
}
