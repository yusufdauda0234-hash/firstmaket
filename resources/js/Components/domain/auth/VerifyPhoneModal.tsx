import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Label } from '@/Components/ui/Label';
import Modal from '@/Components/ui/Modal';
import { PageProps } from '@/Types';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    phone: string;
}

/**
 * Self-service phone verification, opened from wherever a "Verify your
 * phone" prompt lives (currently the vendor dashboard) rather than a
 * dedicated page — verification itself is optional, so nothing forces the
 * user here. Sends a fresh code whenever it opens; the server-side request
 * throttle (OtpService::MAX_REQUESTS_PER_WINDOW) is what actually guards
 * against abuse from repeated opens, not any client-side gating.
 */
export default function VerifyPhoneModal({ open, onClose, phone }: Props) {
    const { flash } = usePage<PageProps>().props;
    // No `identifier` field is actually submitted — OtpService::request()
    // just throws its throttle/gateway-failure message under that key.
    const sendForm = useForm<{ identifier?: string }>({});
    const verifyForm = useForm({ code: '' });
    const hasSentForThisOpen = useRef(false);

    useEffect(() => {
        if (!open) {
            hasSentForThisOpen.current = false;

            return;
        }

        if (hasSentForThisOpen.current) return;
        hasSentForThisOpen.current = true;

        sendForm.post(route('phone.send'), { preserveScroll: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        verifyForm.post(route('phone.verify'), {
            preserveScroll: true,
            onSuccess: () => {
                verifyForm.reset();
                onClose();
            },
        });
    };

    return (
        <Modal open={open} onClose={onClose} title="Verify your phone number" size="sm">
            <p className="text-sm text-gray-500">
                We sent a 6-digit code by SMS to <span className="font-medium text-gray-900">{phone}</span>. Enter
                it below to confirm your number.
            </p>

            {sendForm.errors.identifier && (
                <p className="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                    {sendForm.errors.identifier}
                </p>
            )}

            {flash.devOtpCode && (
                <button
                    type="button"
                    onClick={() => verifyForm.setData('code', flash.devOtpCode ?? '')}
                    className="mt-4 block w-full rounded-md border border-dashed border-brand-300 bg-brand-50 p-3 text-center font-mono text-lg tracking-[0.3em] text-brand-700"
                >
                    {flash.devOtpCode}
                    <span className="mt-1 block font-sans text-[11px] font-normal tracking-normal text-brand-500">
                        Dev mode — no SMS provider configured. Click to fill in.
                    </span>
                </button>
            )}

            <form onSubmit={submit} className="mt-4 space-y-4">
                <div>
                    <Label htmlFor="verify-phone-code">Verification code</Label>
                    <Input
                        id="verify-phone-code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        value={verifyForm.data.code}
                        onChange={(e) => verifyForm.setData('code', e.target.value)}
                        required
                    />
                    <InputError message={verifyForm.errors.code} />
                </div>

                <Button type="submit" disabled={verifyForm.processing} className="w-full">
                    Verify phone number
                </Button>
            </form>

            <div className="mt-4 flex items-center justify-between text-sm">
                <span className="text-gray-500">Didn't get the code?</span>
                <Button
                    variant="ghost"
                    disabled={sendForm.processing}
                    onClick={() => sendForm.post(route('phone.send'), { preserveScroll: true })}
                >
                    Resend code
                </Button>
            </div>
        </Modal>
    );
}
