import { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Input, Button } from '@heroui/react';
import AuthShell from '@/Layouts/AuthShell';
import { ACCENTS, AccentKey } from '@/lib/accent';

const EMOJIS = ['🧔', '👩', '🧑', '👨', '👧', '🧑‍🦱', '🐱', '🚀'];
const COLORS: AccentKey[] = ['primary', 'rose', 'emerald', 'sky', 'amber', 'pink'];

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        avatar_emoji: '🧔',
        color: 'primary',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthShell title="Crear cuenta" subtitle="Únete al hogar">
            <Head title="Registro" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                {/* Avatar + color */}
                <div>
                    <p className="mb-2 text-sm text-default-500">Tu avatar</p>
                    <div className="flex flex-wrap gap-2">
                        {EMOJIS.map((e) => (
                            <button type="button" key={e} onClick={() => setData('avatar_emoji', e)}
                                className={`flex h-10 w-10 items-center justify-center rounded-2xl text-xl transition ${
                                    data.avatar_emoji === e ? 'bg-primary/15 ring-2 ring-primary' : 'bg-content2'
                                }`}>
                                {e}
                            </button>
                        ))}
                    </div>
                    <div className="mt-3 flex gap-2">
                        {COLORS.map((c) => (
                            <button type="button" key={c} onClick={() => setData('color', c)}
                                className={`h-8 w-8 rounded-full ${ACCENTS[c].dot} transition ${data.color === c ? 'ring-2 ring-offset-2 ring-foreground/40' : 'opacity-70'}`} />
                        ))}
                    </div>
                </div>

                <Input label="Nombre" autoComplete="name" variant="bordered" autoFocus
                    value={data.name} onValueChange={(v) => setData('name', v)}
                    isInvalid={!!errors.name} errorMessage={errors.name} />
                <Input type="email" label="Correo" autoComplete="username" variant="bordered"
                    value={data.email} onValueChange={(v) => setData('email', v)}
                    isInvalid={!!errors.email} errorMessage={errors.email} />
                <Input type="password" label="Contraseña" autoComplete="new-password" variant="bordered"
                    value={data.password} onValueChange={(v) => setData('password', v)}
                    isInvalid={!!errors.password} errorMessage={errors.password} />
                <Input type="password" label="Confirmar contraseña" autoComplete="new-password" variant="bordered"
                    value={data.password_confirmation} onValueChange={(v) => setData('password_confirmation', v)}
                    isInvalid={!!errors.password_confirmation} errorMessage={errors.password_confirmation} />

                <Button type="submit" color="primary" size="lg" radius="lg" isLoading={processing} className="mt-2 font-semibold">
                    Crear cuenta
                </Button>
            </form>

            <p className="mt-5 text-center text-sm text-default-500">
                ¿Ya tienen cuenta? <Link href={route('login')} className="font-semibold text-primary">Entrar</Link>
            </p>
        </AuthShell>
    );
}
