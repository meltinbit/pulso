import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';

interface PropertySwitchContextValue {
    activePropertyId: string | null;
    displayedPropertyId: string | null;
    isSwitching: boolean;
    setActivePropertyId: (propertyId: string | null) => void;
    switchProperty: (propertyId: string) => Promise<void>;
    visitWhenReady: (href: string) => void;
}

const PropertySwitchContext = createContext<PropertySwitchContextValue | null>(null);

function getCsrfToken(): string | null {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null;
}

export function PropertySwitchProvider({ children }: { children: React.ReactNode }) {
    const [activePropertyId, setActivePropertyIdState] = useState<string | null>(null);
    const [pendingPropertyId, setPendingPropertyId] = useState<string | null>(null);
    const pendingNavigationRef = useRef<string | null>(null);
    const switchingPromiseRef = useRef<Promise<void> | null>(null);

    const setActivePropertyId = useCallback((propertyId: string | null) => {
        setActivePropertyIdState(propertyId);

        if (propertyId === pendingPropertyId) {
            setPendingPropertyId(null);
        }
    }, [pendingPropertyId]);

    const clearPendingState = useCallback(() => {
        pendingNavigationRef.current = null;
        setPendingPropertyId(null);
    }, []);

    const finishSwitch = useCallback(() => {
        const pendingHref = pendingNavigationRef.current;
        pendingNavigationRef.current = null;

        if (pendingHref) {
            router.visit(pendingHref, {
                preserveScroll: true,
                onCancel: clearPendingState,
                onError: () => clearPendingState(),
            });
            return;
        }

        router.reload({
            preserveScroll: true,
            onCancel: clearPendingState,
            onError: () => clearPendingState(),
        });
    }, [clearPendingState]);

    const switchProperty = useCallback(async (propertyId: string) => {
        if (propertyId === activePropertyId || propertyId === pendingPropertyId) {
            return switchingPromiseRef.current ?? Promise.resolve();
        }

        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            throw new Error('Missing CSRF token.');
        }

        setPendingPropertyId(propertyId);

        const request = fetch(route('properties.switch'), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ property_id: propertyId }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Property switch failed with status ${response.status}.`);
                }

                finishSwitch();
            })
            .catch((error) => {
                clearPendingState();
                console.error(error);
            })
            .finally(() => {
                switchingPromiseRef.current = null;
            });

        switchingPromiseRef.current = request;

        return request;
    }, [activePropertyId, finishSwitch, pendingPropertyId]);

    const visitWhenReady = useCallback((href: string) => {
        if (!switchingPromiseRef.current) {
            router.visit(href, { preserveScroll: true });
            return;
        }

        pendingNavigationRef.current = href;
    }, []);

    const value = useMemo<PropertySwitchContextValue>(() => ({
        activePropertyId,
        displayedPropertyId: pendingPropertyId ?? activePropertyId,
        isSwitching: pendingPropertyId !== null,
        setActivePropertyId,
        switchProperty,
        visitWhenReady,
    }), [activePropertyId, pendingPropertyId, setActivePropertyId, switchProperty, visitWhenReady]);

    return <PropertySwitchContext.Provider value={value}>{children}</PropertySwitchContext.Provider>;
}

export function usePropertySwitch() {
    const context = useContext(PropertySwitchContext);

    if (!context) {
        throw new Error('usePropertySwitch must be used within PropertySwitchProvider.');
    }

    return context;
}
