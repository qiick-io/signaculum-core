import type { SVGAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Signaculum mark: the seal, from knowledge
 * `brand/signaculum/signaculum-mark-{light,dark}.svg` (mark tones B2 in `palette.md`).
 * Logo colours are brand artwork, not theme tokens: body, lid, lid ring and keyhole,
 * one step lighter in dark mode via Tailwind `dark:`. Size via className.
 */
export default function AppLogoIcon({
    className,
    ...props
}: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 512 512"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden={props['aria-hidden'] ?? true}
            className={cn(className)}
        >
            <g transform="translate(6.51 6.51) scale(0.9746)">
                <path
                    className="fill-[#A24A7B] stroke-[#A24A7B] dark:fill-[#DC77AD] dark:stroke-[#DC77AD]"
                    strokeWidth="16"
                    strokeLinejoin="round"
                    d="M76 120V392A180 92 0 0 0 436 392V120Z"
                />
                <path
                    className="fill-[#DC77AD] stroke-[#DC77AD] dark:fill-[#F5A2CC] dark:stroke-[#F5A2CC]"
                    strokeWidth="16"
                    strokeLinejoin="round"
                    d="M76 120A180 91.98 0 1 1 436 120A180 91.98 0 1 1 76 120Z"
                />
                <path
                    className="fill-[#F5A2CC] dark:fill-[#FFD0E4]"
                    fillRule="evenodd"
                    d="M124 120A132 67.45 0 1 1 388 120A132 67.45 0 1 1 124 120ZM164 120A92 47.01 0 1 1 348 120A92 47.01 0 1 1 164 120Z"
                />
                <path
                    className="fill-[#4A2038]"
                    d="M209.8 102.4A46.2 23.61 0 1 1 302.2 102.4A46.2 23.61 0 1 1 209.8 102.4Z"
                />
                <path
                    className="fill-[#4A2038]"
                    d="M251.6 122.2H260.4A11.0 11.0 0 0 1 271.4 133.2V179.4A11.0 11.0 0 0 1 260.4 190.4H251.6A11.0 11.0 0 0 1 240.6 179.4V133.2A11.0 11.0 0 0 1 251.6 122.2Z"
                />
            </g>
        </svg>
    );
}
