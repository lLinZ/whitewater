import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Eraser, Undo2, Trash2, X, Check } from 'lucide-react';

interface Stroke {
    color: string;
    size: number;
    points: [number, number][];
}

const INKS = ['#111827', '#2563eb', '#dc2626', '#059669'];
const SIZES = [2, 4, 8];
const PAPER = '#ffffff';

/**
 * Hoja para garabatear con el dedo o el lápiz, a pantalla completa.
 *
 * Pensada para el iPhone: `touch-action: none` para que el dedo dibuje en vez
 * de mover la página, puntos intermedios (getCoalescedEvents) para que un
 * trazo rápido no salga a pedazos, y la resolución limitada a 2x para que no
 * se arrastre en pantallas 3x.
 *
 * El dibujo se guarda como PNG sobre papel blanco: se ve igual en el modo
 * oscuro, en Monday o impreso.
 */
export default function SketchPad({ onDone, onCancel }: {
    onDone: (file: File) => void;
    onCancel: () => void;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const areaRef = useRef<HTMLDivElement>(null);
    const strokes = useRef<Stroke[]>([]);
    const current = useRef<Stroke | null>(null);
    const [ink, setInk] = useState(INKS[0]);
    const [size, setSize] = useState(SIZES[1]);
    const [erasing, setErasing] = useState(false);
    const [count, setCount] = useState(0);

    const context = () => canvasRef.current?.getContext('2d') ?? null;

    const drawStroke = (ctx: CanvasRenderingContext2D, stroke: Stroke) => {
        const pts = stroke.points;
        ctx.strokeStyle = stroke.color;
        ctx.fillStyle = stroke.color;
        ctx.lineWidth = stroke.size;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        if (pts.length === 1) {
            ctx.beginPath();
            ctx.arc(pts[0][0], pts[0][1], stroke.size / 2, 0, Math.PI * 2);
            ctx.fill();
            return;
        }

        // Curvas por los puntos medios: el trazo sale suave, sin esquinas.
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1]);
        for (let i = 1; i < pts.length - 1; i++) {
            const midX = (pts[i][0] + pts[i + 1][0]) / 2;
            const midY = (pts[i][1] + pts[i + 1][1]) / 2;
            ctx.quadraticCurveTo(pts[i][0], pts[i][1], midX, midY);
        }
        const last = pts[pts.length - 1];
        ctx.lineTo(last[0], last[1]);
        ctx.stroke();
    };

    const redraw = useCallback(() => {
        const canvas = canvasRef.current;
        const ctx = context();
        if (!canvas || !ctx) return;
        const dpr = canvas.width / canvas.getBoundingClientRect().width || 1;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = PAPER;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        strokes.current.forEach((s) => drawStroke(ctx, s));
    }, []);

    // Tamaño real del lienzo = el espacio disponible, a la densidad de la pantalla.
    useEffect(() => {
        const area = areaRef.current;
        const canvas = canvasRef.current;
        if (!area || !canvas) return;

        const fit = () => {
            const rect = area.getBoundingClientRect();
            const dpr = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.round(rect.width * dpr);
            canvas.height = Math.round(rect.height * dpr);
            canvas.style.width = `${rect.width}px`;
            canvas.style.height = `${rect.height}px`;
            redraw();
        };

        fit();
        const observer = new ResizeObserver(fit);
        observer.observe(area);
        return () => observer.disconnect();
    }, [redraw]);

    // Mientras se dibuja, la página de detrás no se mueve.
    useEffect(() => {
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = previous; };
    }, []);

    const point = (e: PointerEvent | React.PointerEvent): [number, number] => {
        const rect = canvasRef.current!.getBoundingClientRect();
        return [e.clientX - rect.left, e.clientY - rect.top];
    };

    const onPointerDown = (e: React.PointerEvent<HTMLCanvasElement>) => {
        e.preventDefault();
        canvasRef.current?.setPointerCapture(e.pointerId);
        // Con lápiz, la presión engrosa el trazo; con el dedo, grosor fijo.
        const width = erasing ? size * 4 : size * (e.pointerType === 'pen' ? 0.6 + e.pressure : 1);
        current.current = { color: erasing ? PAPER : ink, size: width, points: [point(e)] };
        const ctx = context();
        if (ctx) drawStroke(ctx, current.current);
    };

    const onPointerMove = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const stroke = current.current;
        const ctx = context();
        if (!stroke || !ctx) return;
        const events = e.nativeEvent.getCoalescedEvents?.() ?? [e.nativeEvent];
        const from = stroke.points.length - 1;
        events.forEach((ev) => stroke.points.push(point(ev)));
        // Solo el tramo nuevo: redibujar todo en cada movimiento se nota.
        drawStroke(ctx, { ...stroke, points: stroke.points.slice(Math.max(0, from - 1)) });
    };

    const onPointerUp = () => {
        if (!current.current) return;
        strokes.current.push(current.current);
        current.current = null;
        setCount(strokes.current.length);
    };

    const undo = () => {
        strokes.current.pop();
        setCount(strokes.current.length);
        redraw();
    };

    const clear = () => {
        strokes.current = [];
        setCount(0);
        redraw();
    };

    const done = () => {
        canvasRef.current?.toBlob((blob) => {
            if (!blob) return;
            const stamp = new Date().toTimeString().slice(0, 5).replace(':', '-');
            onDone(new File([blob], `garabato-${stamp}.png`, { type: 'image/png' }));
        }, 'image/png');
    };

    return createPortal(
        <div className="fixed inset-0 z-[70] flex flex-col overscroll-contain bg-content2">
            <div className="flex items-center justify-between gap-2 px-3 pb-2 pt-safe">
                <button onClick={onCancel} className="mt-2 flex items-center gap-1 rounded-full px-3 py-2 text-sm text-default-600 active:bg-default-100">
                    <X size={18} /> Cancelar
                </button>
                <p className="mt-2 text-sm font-semibold">Garabato</p>
                <button
                    onClick={done} disabled={count === 0}
                    className="mt-2 flex items-center gap-1 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-40"
                >
                    <Check size={18} /> Listo
                </button>
            </div>

            <div ref={areaRef} className="relative mx-3 flex-1 overflow-hidden rounded-2xl shadow-card">
                <canvas
                    ref={canvasRef}
                    className="absolute inset-0 touch-none"
                    onPointerDown={onPointerDown}
                    onPointerMove={onPointerMove}
                    onPointerUp={onPointerUp}
                    onPointerCancel={onPointerUp}
                />
            </div>

            <div className="flex items-center justify-between gap-2 px-3 pb-safe pt-3">
                <div className="flex items-center gap-2">
                    {INKS.map((color) => (
                        <button
                            key={color} aria-label="Color"
                            onClick={() => { setInk(color); setErasing(false); }}
                            className={`h-8 w-8 rounded-full ring-offset-2 ring-offset-content2 transition active:scale-90 ${
                                ink === color && !erasing ? 'ring-2 ring-primary' : ''
                            }`}
                            style={{ backgroundColor: color }}
                        />
                    ))}
                </div>
                <div className="flex items-center gap-1">
                    {SIZES.map((s) => (
                        <button
                            key={s} aria-label={`Grosor ${s}`} onClick={() => setSize(s)}
                            className={`flex h-9 w-9 items-center justify-center rounded-full ${size === s ? 'bg-default-200' : ''}`}
                        >
                            <span className="rounded-full bg-foreground" style={{ width: s + 3, height: s + 3 }} />
                        </button>
                    ))}
                </div>
            </div>
            <div className="flex items-center justify-around px-3 pb-3 pt-1">
                <button
                    onClick={() => setErasing((v) => !v)}
                    className={`flex flex-col items-center gap-0.5 rounded-xl px-3 py-1.5 text-[11px] ${erasing ? 'bg-primary/15 text-primary' : 'text-default-500'}`}
                >
                    <Eraser size={20} /> Borrador
                </button>
                <button onClick={undo} disabled={count === 0} className="flex flex-col items-center gap-0.5 px-3 py-1.5 text-[11px] text-default-500 disabled:opacity-40">
                    <Undo2 size={20} /> Deshacer
                </button>
                <button onClick={clear} disabled={count === 0} className="flex flex-col items-center gap-0.5 px-3 py-1.5 text-[11px] text-default-500 disabled:opacity-40">
                    <Trash2 size={20} /> Limpiar
                </button>
            </div>
        </div>,
        document.body,
    );
}
