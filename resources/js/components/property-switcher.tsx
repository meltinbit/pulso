import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, usePage } from '@inertiajs/react';

interface GaPropertyItem {
    id: number;
    display_name: string;
    property_id: string;
}

interface PageProps {
    activeProperty: GaPropertyItem | null;
    userProperties: GaPropertyItem[];
    [key: string]: unknown;
}

export function PropertySwitcher() {
    const { activeProperty, userProperties } = usePage<PageProps>().props;

    if (!userProperties || userProperties.length === 0) {
        return null;
    }

    function handleSwitch(propertyId: string) {
        router.post(
            route('properties.switch'),
            { property_id: propertyId },
            { preserveScroll: true },
        );
    }

    return (
        <Select value={activeProperty?.id?.toString() ?? ''} onValueChange={handleSwitch}>
            <SelectTrigger className="h-8 w-[180px] text-xs">
                <SelectValue placeholder="Select property" />
            </SelectTrigger>
            <SelectContent>
                {userProperties.map((property) => (
                    <SelectItem key={property.id} value={property.id.toString()}>
                        {property.display_name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
