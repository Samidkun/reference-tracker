import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

/**
 * Text input.
 *
 * `invalid` is a convenience: it sets aria-invalid and paints the red border,
 * so a field can never have an error message without the matching visual and
 * programmatic state. Callers still pass aria-describedby with the error id.
 */
export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, invalid = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            aria-invalid={invalid ? 'true' : undefined}
            className={
                'rounded-md shadow-sm focus:ring-2 ' +
                (invalid
                    ? 'border-red-500 focus:border-red-500 focus:ring-red-500 '
                    : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 ') +
                className
            }
            ref={localRef}
        />
    );
});
