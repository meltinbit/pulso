import { Link, type LinkProps } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { usePropertySwitch } from './property-switch-provider';

export function PropertyAwareLink({ href, onClick, ...props }: LinkProps) {
    const { isSwitching, visitWhenReady } = usePropertySwitch();

    const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
        onClick?.(event);

        if (
            event.defaultPrevented ||
            !isSwitching ||
            typeof href !== 'string' ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        event.preventDefault();
        visitWhenReady(href);
    };

    return <Link {...props} href={href} onClick={handleClick} />;
}
