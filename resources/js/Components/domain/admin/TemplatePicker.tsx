import Modal from '@/Components/ui/Modal';
import { router } from '@inertiajs/react';
import { Sparkles, Wand2 } from 'lucide-react';
import { useState } from 'react';

export interface Template {
    key: string;
    name: string;
    summary: string;
    /** How many rows applying it would create. */
    count: number;
}

interface Props {
    templates: Template[];
    /** Where to POST the chosen key. */
    action: string;
    /** What is being created, for the copy: "delivery rates", "plan terms". */
    noun: string;
    /** Shown under the heading when the screen has nothing set up yet. */
    empty?: boolean;
}

/**
 * One-click starter settings.
 *
 * A fresh FirstMaket has nothing configured, and several of these screens are
 * the difference between a working shop and one that looks broken. Inventing
 * a sensible instalment schedule before you have sold anything is a poor
 * first hour, so the common shapes are here to be adopted and then edited.
 *
 * Applying only ever fills gaps — an existing row is never overwritten — so
 * clicking twice cannot undo a decision somebody made by hand. That is what
 * lets the button stay visible after setup rather than being a one-time
 * wizard the admin has to find again later.
 */
export default function TemplatePicker({ templates, action, noun, empty = false }: Props) {
    const [open, setOpen] = useState(false);
    const [applying, setApplying] = useState<string | null>(null);

    if (templates.length === 0) {
        return null;
    }

    const apply = (key: string) => {
        setApplying(key);

        router.post(
            action,
            { template: key },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setApplying(null),
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={
                    empty
                        ? 'inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-700 active:scale-95'
                        : 'inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 transition hover:border-brand-300 hover:text-brand-700 active:scale-95'
                }
            >
                <Wand2 className="h-4 w-4" /> Use a template
            </button>

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={`Start from a template`}
                description={`Adds ready-made ${noun} you can edit afterwards. Nothing already set up is changed.`}
                size="lg"
            >
                <div className="space-y-2">
                    {templates.map((template) => (
                        <button
                            key={template.key}
                            type="button"
                            disabled={applying !== null}
                            onClick={() => apply(template.key)}
                            className="flex w-full items-start gap-3 rounded-xl border border-gray-100 bg-white p-4 text-left transition hover:border-brand-300 hover:bg-brand-50/40 disabled:opacity-50"
                        >
                            <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                <Sparkles className="h-4 w-4" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-bold text-gray-900">
                                    {template.name}
                                </span>
                                <span className="mt-0.5 block text-xs leading-relaxed text-gray-500">
                                    {template.summary}
                                </span>
                            </span>
                            <span className="shrink-0 rounded-lg bg-gray-100 px-2 py-1 text-[11px] font-bold text-gray-500">
                                {applying === template.key
                                    ? 'Adding…'
                                    : `${template.count} row${template.count === 1 ? '' : 's'}`}
                            </span>
                        </button>
                    ))}
                </div>
            </Modal>
        </>
    );
}
