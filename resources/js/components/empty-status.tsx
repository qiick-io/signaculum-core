import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStatusProps = {
    children: ReactNode;
    className?: string;
};

/**
 * Visible empty-state copy that is also announced once via `role="status"`.
 */
export function EmptyStatus({ children, className }: EmptyStatusProps) {
    return (
        <span role="status" className={cn(className)}>
            {children}
        </span>
    );
}
