import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <Head title="Sign in" />
            <main className="flex min-h-screen items-center justify-center px-4">
                <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
                    <h1 className="text-xl font-semibold text-slate-900">Sign in to Knowledge Assistant</h1>
                    <form onSubmit={submit} noValidate className="mt-6 flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="email" className="text-sm font-medium text-slate-900">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                autoComplete="username"
                                autoFocus
                                required
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                aria-invalid={form.errors.email ? true : undefined}
                                aria-describedby={form.errors.email ? 'email-error' : undefined}
                                className="rounded-md border border-slate-400 px-3 py-2 text-slate-900"
                            />
                            {form.errors.email && (
                                <p id="email-error" role="alert" className="text-sm text-red-800">
                                    {form.errors.email}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="password" className="text-sm font-medium text-slate-900">
                                Password
                            </label>
                            <input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                aria-invalid={form.errors.password ? true : undefined}
                                aria-describedby={form.errors.password ? 'password-error' : undefined}
                                className="rounded-md border border-slate-400 px-3 py-2 text-slate-900"
                            />
                            {form.errors.password && (
                                <p id="password-error" role="alert" className="text-sm text-red-800">
                                    {form.errors.password}
                                </p>
                            )}
                        </div>
                        <label className="flex items-center gap-2 text-sm text-slate-800">
                            <input
                                type="checkbox"
                                checked={form.data.remember}
                                onChange={(event) => form.setData('remember', event.target.checked)}
                                className="size-4"
                            />
                            Remember me
                        </label>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-md bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-60"
                        >
                            {form.processing ? 'Signing in…' : 'Sign in'}
                        </button>
                    </form>
                </div>
            </main>
        </>
    );
}
