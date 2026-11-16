import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type TruncatedTextProps = {
    text: string;
    className?: string;
};

/**
 * CSS truncate with tooltip only when the text actually overflows.
 */
export function TruncatedText({ text, className }: TruncatedTextProps) {
    const ref = useRef<HTMLSpanElement>(null);
    const [truncated, setTruncated] = useState(false);

    const measure = useCallback(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        setTruncated(element.scrollWidth > element.clientWidth + 1);
    }, []);

    useLayoutEffect(() => {
        measure();

        const element = ref.current;

        if (!element || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => measure());
        observer.observe(element);

        return () => observer.disconnect();
    }, [measure, text]);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    ref={ref}
                    className={cn('block min-w-0 truncate', className)}
                >
                    {text}
                </span>
            </TooltipTrigger>
            {truncated ? (
                <TooltipContent className="max-w-sm break-words">
                    {text}
                </TooltipContent>
            ) : null}
        </Tooltip>
    );
}
