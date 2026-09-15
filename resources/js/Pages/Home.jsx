export default function Home({ message }) {
    return (
        <div className="min-h-screen flex items-center justify-center">
            <div className="text-center">
                <h1 className="text-3xl font-semibold tracking-tight">{message}</h1>
                <p className="mt-2 text-slate-600">Inertia + React + Tailwind berjalan.</p>
            </div>
        </div>
    );
}
