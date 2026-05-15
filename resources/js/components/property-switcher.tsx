import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect } from 'react';
import { usePropertySwitch } from './property-switch-provider';

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
    const { displayedPropertyId, isSwitching, setActivePropertyId, switchProperty } = usePropertySwitch();

    useEffect(() => {
        setActivePropertyId(activeProperty?.id?.toString() ?? null);
    }, [activeProperty?.id, setActivePropertyId]);

    if (!userProperties || userProperties.length === 0) {
        return null;
    }

    function handleSwitch(propertyId: string) {
        void switchProperty(propertyId);
    }

    return (
        <Select disabled={isSwitching} value={displayedPropertyId ?? activeProperty?.id?.toString() ?? ''} onValueChange={handleSwitch}>
            <SelectTrigger className="h-8 w-[180px] text-xs">
                <SelectValue placeholder="Select property" />
                {isSwitching ? <LoaderCircle className="ml-2 h-3.5 w-3.5 animate-spin text-muted-foreground" /> : null}
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
