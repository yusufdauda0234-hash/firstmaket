import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'));
    };

    return (
        <GuestLayout>
            <Head title="Create account" />

            <h1 className="mb-6 text-lg font-semibold text-gray-900 dark:text-gray-100">Create your account</h1>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <Label htmlFor="name">Full name</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>

                <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />
                    <InputError message={errors.email} />
                </div>

                <div>
                    <Label htmlFor="phone">Phone number</Label>
                    <Input
                        id="phone"
                        type="tel"
                        placeholder="+2348012345678"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        required
                    />
                    <InputError message={errors.phone} />
                </div>

                <div>
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    <InputError message={errors.password} />
                </div>

                <div>
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        required
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Create account
                </Button>
            </form>

            <p className="mt-4 text-sm text-gray-600 dark:text-gray-400">
                Already have an account?{' '}
                <Link href={route('login')} className="font-medium text-brand-600 hover:underline">
                    Log in
                </Link>
            </p>
        </GuestLayout>
    );
}
