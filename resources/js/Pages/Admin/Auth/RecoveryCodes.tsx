import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Copy, Download, KeyRound } from 'lucide-react';
import { useState } from 'react';

interface Props {
    codes: string[];
    [key: string]: unknown;
}

/**
 * The only time recovery codes are readable.
 *
 * They are hashed at rest, so this page cannot be revisited — refreshing loses
 * them for good, which is exactly why the warning is this loud.
 */
export default function RecoveryCodes() {
    const { codes } = usePage<Props>().props;
    const [copied, setCopied] = useState(false);

    const asText = codes.join('\n');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(asText);
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        } catch {
            // Clipboard blocked — the codes are on screen and downloadable.
        }
    };

    const download = () => {
        const blob = new Blob(
            [`FirstMaket admin recovery codes\nSaved ${new Date().toISOString()}\n\n${asText}\n`],
            { type: 'text/plain' },
        );
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'firstmaket-recovery-codes.txt';
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-900 p-4">
            <Head title="Recovery codes" />

            <div className="w-full max-w-lg rounded-2xl bg-white p-8 shadow-xl">
                <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                    <KeyRound className="h-6 w-6" />
                </span>

                <h1 className="mt-4 text-xl font-extrabold text-gray-900">Save your recovery codes</h1>
                <p className="mt-1.5 text-sm text-gray-500">
                    If you lose your phone, one of these gets you back in. Each code works once.
                </p>

                <div className="mt-4 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3.5">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                    <p className="text-sm text-amber-900">
                        <span className="font-bold">This is the only time you will see them.</span> We store only a
                        one-way hash, so nobody — including us — can show them to you again. Refreshing this page
                        loses them.
                    </p>
                </div>

                <ul className="mt-5 grid grid-cols-2 gap-2 rounded-xl bg-gray-50 p-4 font-mono text-sm text-gray-800">
                    {codes.map((code) => (
                        <li key={code} className="tracking-wide">
                            {code}
                        </li>
                    ))}
                </ul>

                <div className="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={copy}
                        className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50"
                    >
                        {copied ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
                        {copied ? 'Copied' : 'Copy all'}
                    </button>
                    <button
                        type="button"
                        onClick={download}
                        className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-xs font-bold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50"
                    >
                        <Download className="h-3.5 w-3.5" /> Download
                    </button>
                </div>

                <Link
                    href={route('admin.dashboard')}
                    className="mt-6 block w-full rounded-full bg-brand-600 py-3 text-center text-sm font-bold text-white transition hover:bg-brand-700"
                >
                    I have saved them — continue
                </Link>
            </div>
        </div>
    );
}
