import { router } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

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

    // Once the server has stored the new property, any later page visit renders
    // it. A visit that cancels the reload below (e.g. a click in the sidebar)
    // must not revert the selector, so pending state is only dropped after the
    // next navigation, not on cancel.
    const switchConfirmedRef = useRef(false);

    useEffect(() => {
        return router.on('navigate', () => {
            if (switchConfirmedRef.current) {
                switchConfirmedRef.current = false;
                setPendingPropertyId(null);
            }
        });
    }, []);

    const finishSwitch = useCallback(() => {
        switchConfirmedRef.current = true;

        // Prefetched pages were rendered for the previous property.
        router.flushAll();

        const pendingHref = pendingNavigationRef.current;
        pendingNavigationRef.current = null;

        if (pendingHref) {
            router.visit(pendingHref, { preserveScroll: true });
            return;
        }

        router.reload();
    }, []);

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
                switchConfirmedRef.current = false;
                clearPendingState();
                console.error(error);
            })
            .finally(() => {
                switchingPromiseRef.current = null;
            });

        switchingPromiseRef.current = request;

        return request;
    }, [activePropertyId, clearPendingState, finishSwitch, pendingPropertyId]);

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
