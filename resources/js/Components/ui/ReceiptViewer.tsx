import { useState } from 'react';
import { Modal, ModalContent } from '@heroui/react';
import { Paperclip, ExternalLink } from 'lucide-react';

/**
 * Miniatura del comprobante que abre la foto a pantalla completa.
 *
 * El punto de adjuntar el recibo es poder enseñarlo: en el teléfono, una
 * miniatura de 40px no sirve de evidencia de nada.
 */
export default function ReceiptViewer({ url, alt, size = 40 }: { url: string; alt: string; size?: number }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={`Ver comprobante de ${alt}`}
                className="shrink-0 overflow-hidden rounded-lg ring-1 ring-divider active:scale-90"
                style={{ width: size, height: size }}
            >
                <img src={url} alt="" className="h-full w-full object-cover" />
            </button>

            <Modal isOpen={open} onClose={() => setOpen(false)} placement="center" backdrop="blur" size="lg">
                <ModalContent className="bg-content1">
                    <div className="flex flex-col gap-3 p-4">
                        <div className="flex items-center gap-2">
                            <Paperclip size={16} className="text-default-400" />
                            <p className="min-w-0 flex-1 truncate text-sm font-medium">{alt}</p>
                            <a
                                href={url} target="_blank" rel="noreferrer"
                                className="flex items-center gap-1 text-xs text-primary"
                            >
                                Abrir <ExternalLink size={13} />
                            </a>
                        </div>
                        <img src={url} alt={alt} className="max-h-[70vh] w-full rounded-2xl object-contain" />
                    </div>
                </ModalContent>
            </Modal>
        </>
    );
}
