import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export default function AppLogo() {
    const { name } = usePage<SharedData>().props;

    return (
        <>
            <img src="/logo.jpg" alt={name} className="size-8 rounded-lg object-cover" />
            <div className="ml-1.5 grid flex-1 text-left text-sm">
                <span className="truncate leading-none font-bold tracking-tight">{name}</span>
            </div>
        </>
    );
}
