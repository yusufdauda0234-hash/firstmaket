import { Button } from '@/Components/ui/Button';
import { InputError } from '@/Components/ui/InputError';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function AdminLogin() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.login'));
    };

    return (
        <GuestLayout>
            <Head title="Admin log in" />

            <h1 className="mb-6 text-lg font-semibold text-gray-900 dark:text-gray-100">FirstMarket Staff Sign In</h1>

            <form onSubmit={submit} className="space-y-4">
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
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    <InputError message={errors.password} />
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Log in
                </Button>
            </form>
        </GuestLayout>
    );
}
