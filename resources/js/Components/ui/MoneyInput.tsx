import { Input } from '@/Components/ui/Input';
import { formatNumber, parseNumber } from '@/Utils/money';
import { InputHTMLAttributes, useEffect, useState } from 'react';

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'type'> {
    /** The real value, in whole naira. Empty string when the field is blank. */
    value: number | '';
    onChange: (value: number | '') => void;
    /** Allow kobo. Off by default — most amounts here are whole naira. */
    allowDecimals?: boolean;
}

/**
 * Money field that shows thousand separators while you type.
 *
 * A text input rather than `type="number"`: a number input rejects the commas
 * outright, and ₦1,250,000 read as `1250000` is genuinely hard to check at a
 * glance — which matters when the figure is a price a customer will pay.
 *
 * The separators are presentation only. The caller receives a clean number, so
 * nothing downstream has to know this formatting happened.
 */
export function MoneyInput({ value, onChange, allowDecimals = false, ...props }: Props) {
    // Local text so a half-typed "1,2" survives; the parsed number is what
    // leaves the component.
    const [text, setText] = useState(() => (value === '' ? '' : formatNumber(value, allowDecimals)));

    // Follow the value when the form changes it from outside — loading a
    // product to edit, or a reset after submit.
    useEffect(() => {
        const current = parseNumber(text);

        if (value === '' && text !== '') {
            setText('');
        } else if (value !== '' && value !== current) {
            setText(formatNumber(value, allowDecimals));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    return (
        <Input
            {...props}
            type="text"
            // Numeric keypad on mobile without the browser rejecting commas.
            inputMode={allowDecimals ? 'decimal' : 'numeric'}
            autoComplete="off"
            value={text}
            onChange={(e) => {
                const raw = e.target.value;
                const parsed = parseNumber(raw);

                if (parsed === null) {
                    setText(raw === '' ? '' : raw);
                    onChange('');

                    return;
                }

                // Re-group as they type, but leave a trailing "." or ".x" alone
                // so a decimal can actually be entered.
                const midDecimal = allowDecimals && /\.\d?$/.test(raw);
                setText(midDecimal ? raw : formatNumber(parsed, allowDecimals));
                onChange(parsed);
            }}
            onBlur={(e) => {
                const parsed = parseNumber(e.target.value);
                setText(parsed === null ? '' : formatNumber(parsed, allowDecimals));
                props.onBlur?.(e);
            }}
        />
    );
}
