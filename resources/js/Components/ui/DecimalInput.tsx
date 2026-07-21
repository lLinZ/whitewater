import { ComponentProps, forwardRef } from 'react';
import { Input } from '@heroui/react';
import { sanitizeDecimal } from '@/lib/format';

type InputProps = ComponentProps<typeof Input>;

interface Props extends Omit<InputProps, 'type' | 'value' | 'onValueChange'> {
    value: string;
    onValueChange: (value: string) => void;
}

/**
 * Campo para montos y cantidades. Usa type="text" en vez de type="number"
 * porque en iPhone el teclado decimal en español trae coma, y un input
 * numérico la rechaza (obligaba a copiar y pegar un punto). Aquí la coma se
 * acepta y se normaliza a punto mientras escribes.
 */
const DecimalInput = forwardRef<HTMLInputElement, Props>(function DecimalInput(
    { value, onValueChange, ...rest },
    ref,
) {
    return (
        <Input
            {...rest}
            ref={ref}
            type="text"
            inputMode="decimal"
            autoComplete="off"
            value={value}
            onValueChange={(v) => onValueChange(sanitizeDecimal(v))}
        />
    );
});

export default DecimalInput;
