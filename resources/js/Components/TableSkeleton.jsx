/**
 * The design contract requires a loading state for the list. Inertia's
 * top progress bar is 3px and easy to miss on a slow paginate; this is the
 * specified row skeleton.
 */
export default function TableSkeleton({ rows = 5 }) {
    return (
        <div
            className="overflow-hidden bg-white shadow-sm sm:rounded-lg"
            role="status"
            aria-live="polite"
            aria-label="Loading references"
        >
            <div className="divide-y divide-gray-100">
                {Array.from({ length: rows }).map((_, i) => (
                    <div key={i} className="flex items-center gap-4 px-6 py-4">
                        <div className="flex-1 space-y-2">
                            <div className="h-4 w-2/3 animate-pulse rounded bg-gray-200" />
                            <div className="h-3 w-1/3 animate-pulse rounded bg-gray-100" />
                        </div>
                        <div className="h-3 w-16 animate-pulse rounded bg-gray-100" />
                        <div className="h-3 w-10 animate-pulse rounded bg-gray-100" />
                    </div>
                ))}
            </div>
        </div>
    );
}
