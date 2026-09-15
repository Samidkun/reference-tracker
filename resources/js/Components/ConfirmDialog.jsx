import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';

/**
 * Confirmation for destructive actions.
 *
 * Replaces window.confirm(), which the design contract rules out: unstyled,
 * blocks the main thread, untestable, and string-concatenating a
 * user-controlled title prints raw markup. The title is a text node here, so
 * React escapes it.
 *
 * STRUCTURE — load-bearing, do not "tidy" it.
 * Headless UI v2 renders <Dialog> in place (no portal) and the element it
 * renders is the one carrying role="dialog". If that element is left in normal
 * flow while its children are `fixed`, it collapses to height 0 and both
 * Playwright and screen readers correctly report the dialog as not visible.
 * The wrapper must therefore be `fixed inset-0 flex` so it spans the viewport
 * and the panel sits inside it. This mirrors Breeze's own Modal.jsx.
 */
export default function ConfirmDialog({
    show,
    title = 'Are you sure?',
    description,
    confirmLabel = 'Delete',
    onConfirm,
    onCancel,
}) {
    return (
        <Dialog
            open={show}
            onClose={onCancel}
            className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4"
        >
            <div className="fixed inset-0 bg-black/40" aria-hidden="true" />

            <DialogPanel className="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <DialogTitle className="text-base font-semibold text-gray-900">
                    {title}
                </DialogTitle>

                {description && (
                    <p className="mt-2 break-words text-sm text-gray-600">{description}</p>
                )}

                <div className="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
                    >
                        {confirmLabel}
                    </button>
                </div>
            </DialogPanel>
        </Dialog>
    );
}
