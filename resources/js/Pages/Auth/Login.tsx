import { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Input, Button, Checkbox } from '@heroui/react';
import AuthShell from '@/Layouts/AuthShell';

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <AuthShell title="Whitewater" subtitle="El hogar, organizado">
            <Head title="Entrar" />
            {status && <div className="mb-4 rounded-xl bg-emerald-50 p-2 text-center text-sm font-medium text-emerald-600">{status}</div>}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Input
                    type="email" label="Correo" autoComplete="username" autoFocus variant="bordered"
                    value={data.email} onValueChange={(v) => setData('email', v)}
                    isInvalid={!!errors.email} errorMessage={errors.email}
                />
                <Input
                    type="password" label="Contraseña" autoComplete="current-password" variant="bordered"
                    value={data.password} onValueChange={(v) => setData('password', v)}
                    isInvalid={!!errors.password} errorMessage={errors.password}
                />
                <div className="flex items-center justify-between">
                    <Checkbox size="sm" isSelected={data.remember} onValueChange={(v) => setData('remember', v)}>
                        Recuérdame
                    </Checkbox>
                    {canResetPassword && (
                        <Link href={route('password.request')} className="text-sm text-primary">¿Olvidaste tu clave?</Link>
                    )}
                </div>
                <Button type="submit" color="primary" size="lg" radius="lg" isLoading={processing} className="mt-2 font-semibold">
                    Entrar
                </Button>
            </form>

            <p className="mt-5 text-center text-sm text-default-500">
                ¿No tienen cuenta? <Link href={route('register')} className="font-semibold text-primary">Crear una</Link>
            </p>
        </AuthShell>
    );
}
