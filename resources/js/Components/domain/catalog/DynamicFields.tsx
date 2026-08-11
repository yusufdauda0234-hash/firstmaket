import { Input } from '@/Components/ui/Input';
import { InputError } from '@/Components/ui/InputError';
import { Select } from '@/Components/ui/Select';

export type FieldType =
    | 'text'
    | 'textarea'
    | 'number'
    | 'select'
    | 'multiselect'
    | 'boolean'
    | 'url'
    | 'bullet_list'
    | 'numbered_list';

export interface AttributeField {
    id: number;
    key: string;
    label: string;
    type: FieldType;
    options: string[];
    unit: string | null;
    helpText: string | null;
    placeholder: string | null;
    required: boolean;
}

/**
 * Everything the nine field types can produce. Deliberately concrete rather
 * than `unknown`: Inertia's useForm only accepts values it knows how to
 * serialise, and a wider type here fails to compile at every call site.
 */
export type AttributeValue = string | number | boolean | string[] | null;

export type AttributeValues = Record<string, AttributeValue>;

interface Props {
    fields: AttributeField[];
    values: AttributeValues;
    errors: Record<string, string>;
    onChange: (key: string, value: AttributeValue) => void;
    disabled?: boolean;
}

/**
 * Renders the product form fields that staff defined in admin, for whichever
 * category the vendor picked.
 *
 * Nothing here knows what a phone or a sofa is — it only knows the nine
 * field types. That is the point: adding "Screen size" to Electronics is a
 * row in the admin table, not a change to this file.
 */
export default function DynamicFields({ fields, values, errors, onChange, disabled = false }: Props) {
    if (fields.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {fields.map((field) => {
                // Server-side errors arrive keyed by the posted path.
                const error = errors[`attributes.${field.key}`];
                const value = values[field.key];
                const wide =
                    field.type === 'textarea' ||
                    field.type === 'multiselect' ||
                    field.type === 'bullet_list' ||
                    field.type === 'numbered_list';

                return (
                    <div key={field.id} className={wide ? 'sm:col-span-2' : undefined}>
                        <label
                            htmlFor={`attr-${field.key}`}
                            className="mb-1.5 block text-xs font-bold text-gray-700"
                        >
                            {field.label}
                            {field.unit && <span className="ml-1 font-normal text-gray-400">({field.unit})</span>}
                            {!field.required && (
                                <span className="ml-1.5 font-normal text-gray-400">optional</span>
                            )}
                        </label>

                        <FieldInput
                            field={field}
                            value={value}
                            disabled={disabled}
                            onChange={(next) => onChange(field.key, next)}
                        />

                        {field.helpText && !error && (
                            <p className="mt-1 text-xs text-gray-400">{field.helpText}</p>
                        )}
                        <InputError message={error} className="mt-1" />
                    </div>
                );
            })}
        </div>
    );
}

function FieldInput({
    field,
    value,
    disabled,
    onChange,
}: {
    field: AttributeField;
    value: AttributeValue;
    disabled: boolean;
    onChange: (value: AttributeValue) => void;
}) {
    const id = `attr-${field.key}`;

    switch (field.type) {
        case 'textarea':
            return (
                <textarea
                    id={id}
                    rows={3}
                    disabled={disabled}
                    placeholder={field.placeholder ?? ''}
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:bg-gray-50"
                />
            );

        case 'number':
            return (
                <Input
                    id={id}
                    type="number"
                    inputMode="decimal"
                    step="any"
                    disabled={disabled}
                    placeholder={field.placeholder ?? ''}
                    value={(value as string | number) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'url':
            return (
                <Input
                    id={id}
                    type="url"
                    disabled={disabled}
                    placeholder={field.placeholder ?? 'https://…'}
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        // One point per line, stored as an array. A textarea rather than
        // repeatable rows because vendors paste these in from a spec sheet,
        // and the server strips any bullet characters that come with them.
        case 'bullet_list':
        case 'numbered_list':
            return (
                <>
                    <textarea
                        id={id}
                        rows={5}
                        disabled={disabled}
                        placeholder={field.placeholder ?? 'One point per line'}
                        value={Array.isArray(value) ? value.join('\n') : ((value as string) ?? '')}
                        onChange={(e) => onChange(e.target.value.split('\n'))}
                        className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:bg-gray-50"
                    />
                    <p className="mt-1 text-[11px] text-gray-400">
                        One per line — they show as{' '}
                        {field.type === 'numbered_list' ? 'a numbered list' : 'bullet points'} on the
                        product page.
                    </p>
                </>
            );

        case 'boolean':
            return (
                <label className="flex cursor-pointer items-center gap-2.5 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700">
                    <input
                        id={id}
                        type="checkbox"
                        disabled={disabled}
                        checked={Boolean(value)}
                        onChange={(e) => onChange(e.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500/20"
                    />
                    Yes
                </label>
            );

        case 'select':
            return (
                <Select
                    id={id}
                    disabled={disabled}
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    className={value ? undefined : 'text-gray-400'}
                >
                    <option value="">Select {field.label.toLowerCase()}</option>
                    {field.options.map((option) => (
                        <option key={option} value={option} className="text-gray-900">
                            {option}
                        </option>
                    ))}
                </Select>
            );

        case 'multiselect': {
            const selected = Array.isArray(value) ? (value as string[]) : [];

            // Chips rather than a native multi-select: holding Ctrl to pick
            // several is a desktop-only convention most shoppers-turned-
            // vendors never discover, and it is unusable on a phone.
            return (
                <div className="flex flex-wrap gap-2">
                    {field.options.map((option) => {
                        const on = selected.includes(option);

                        return (
                            <button
                                key={option}
                                type="button"
                                disabled={disabled}
                                aria-pressed={on}
                                onClick={() =>
                                    onChange(
                                        on
                                            ? selected.filter((v) => v !== option)
                                            : [...selected, option],
                                    )
                                }
                                className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                                    on
                                        ? 'border-brand-600 bg-brand-600 text-white'
                                        : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400'
                                } disabled:opacity-50`}
                            >
                                {option}
                            </button>
                        );
                    })}
                </div>
            );
        }

        default:
            return (
                <Input
                    id={id}
                    type="text"
                    disabled={disabled}
                    placeholder={field.placeholder ?? ''}
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }
}
