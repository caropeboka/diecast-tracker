import { useId } from 'react';
import type { SVGAttributes } from 'react';

/**
 * Diecast Tracker mark: a sports-car silhouette with speed lines, drawn in currentColor.
 * Wheel wells are cut out with a mask so the wheels read on any background.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const mask = useId();

    return (
        <svg viewBox="0 0 64 40" xmlns="http://www.w3.org/2000/svg" {...props}>
            <defs>
                <mask id={mask}>
                    <rect width="64" height="40" fill="white" />
                    <circle cx="21" cy="30" r="6.6" fill="black" />
                    <circle cx="48" cy="30" r="6.6" fill="black" />
                    {/* Window */}
                    <path
                        d="M28.6 20.2c2-2.3 4.5-3.5 7.4-3.5h6.6c2.3 0 4.4 1.1 5.8 3l.4.5Z"
                        fill="black"
                    />
                </mask>
            </defs>
            {/* Speed lines */}
            <rect x="0" y="17" width="9" height="2.6" rx="1.3" />
            <rect x="3" y="22.5" width="7" height="2.6" rx="1.3" />
            {/* Body */}
            <path
                mask={`url(#${mask})`}
                d="M12 27.6c0-3.4 2.1-5 5.1-5.6l8.8-1.8c2.6-3.6 6.3-5.9 10.9-5.9h7.2c3.6 0 6.7 1.9 8.8 4.8l1.5 2.1c4.1.6 6.4 2.9 6.4 6.1v1.4a2 2 0 0 1-2 2H14a2 2 0 0 1-2-2Z"
            />
            {/* Wheels with hubs */}
            <path
                fillRule="evenodd"
                d="M21 25a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM48 25a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"
            />
        </svg>
    );
}
