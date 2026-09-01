import { useEffect, useState } from 'react';
import { usePage, router } from '@inertiajs/react';
import { Switch, Button } from '@heroui/react';
import { Bell } from 'lucide-react';
import { Card } from '@/Components/ui/primitives';
import {
    isPushSupported, subscribeToPush, unsubscribeFromPush, getExistingSubscription, contentEncoding,
} from '@/lib/push';
import { PageProps } from '@/types';

export default function NotificationsToggle() {
    const { notifications } = usePage<PageProps>().props;
    const [supported, setSupported] = useState(true);
    const [enabled, setEnabled] = useState(false);
    const [busy, setBusy] = useState(false);
    const [standalone, setStandalone] = useState(true);

    useEffect(() => {
        setSupported(isPushSupported());
        const isStandalone =
            window.matchMedia('(display-mode: standalone)').matches ||
            (window.navigator as { standalone?: boolean }).standalone === true;
        setStandalone(isStandalone);

        getExistingSubscription().then((sub) => {
            setEnabled(!!sub);
            if (!sub) return;
            // Reenvía la suscripción al servidor: así se corrige el cifrado de
            // las que se guardaron antes con el valor equivocado.
            const json = sub.toJSON();
            router.post('/notificaciones/suscribir', {
                endpoint: json.endpoint, keys: json.keys, contentEncoding: contentEncoding(), silent: true,
            }, { preserveScroll: true, preserveState: true });
        }).catch(() => {});
    }, []);

    const toggle = async (on: boolean) => {
        setBusy(true);
        try {
            if (on) {
                const sub = await subscribeToPush(notifications.vapidPublicKey || '');
                if (!sub) { setBusy(false); return; } // permiso denegado o no soportado
                router.post('/notificaciones/suscribir', {
                    endpoint: sub.endpoint, keys: sub.keys, contentEncoding: contentEncoding(),
                }, {
                    preserveScroll: true,
                    onSuccess: () => setEnabled(true),
                    onFinish: () => setBusy(false),
                });
            } else {
                const endpoint = await unsubscribeFromPush();
                if (endpoint) {
                    router.post('/notificaciones/desuscribir', { endpoint }, {
                        preserveScroll: true,
                        onSuccess: () => setEnabled(false),
                        onFinish: () => setBusy(false),
                    });
                } else {
                    setEnabled(false);
                    setBusy(false);
                }
            }
        } catch {
            setBusy(false);
        }
    };

    if (!supported) {
        return (
            <Card className="!py-3 text-sm text-default-500">
                🔔 Este navegador no soporta notificaciones push.
            </Card>
        );
    }

    return (
        <Card className="!py-3">
            <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <Bell size={18} />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">Recordatorios de tareas</p>
                    <p className="text-xs text-default-400">Aviso diario si quedan rutinas por hacer</p>
                </div>
                <Switch isSelected={enabled} onValueChange={toggle} isDisabled={busy} color="primary" />
            </div>

            {enabled && (
                <Button
                    size="sm" variant="flat" radius="full" className="mt-3"
                    onPress={() => router.post('/notificaciones/probar', {}, { preserveScroll: true })}
                >
                    Enviar prueba 📩
                </Button>
            )}

            {!standalone && (
                <p className="mt-3 rounded-2xl bg-content2 px-3 py-2 text-xs text-default-500">
                    📱 <b>En iPhone:</b> abre esta web en Safari, toca <b>Compartir</b> → <b>Añadir a inicio</b>,
                    y abre la app desde ese ícono para poder activar las notificaciones.
                </p>
            )}
        </Card>
    );
}
