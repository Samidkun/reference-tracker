/**
 * Inline field error.
 *
 * The `id` matters: the input points at it with aria-describedby, so a screen
 * reader announces the message as part of the field rather than as loose text
 * that happens to sit nearby. Callers pass a stable `${name}-error`.
 */
export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <p
            {...props}
            role="alert"
            className={'text-sm text-red-600 ' + className}
        >
            {message}
        </p>
    ) : null;
}
