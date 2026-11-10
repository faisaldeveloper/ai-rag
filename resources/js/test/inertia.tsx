/**
 * A stand-in for @inertiajs/react in component tests: pages render with the
 * shared props below and no Inertia router.
 *
 * Usage: vi.mock('@inertiajs/react', () => import('../test/inertia'));
 */
import type { ReactNode } from 'react';
import type { SharedProps } from '../types';

export const sharedProps: SharedProps = {
    auth: { user: { id: 1, name: 'Ada Admin', email: 'ada@example.com', is_admin: true } },
};

export function usePage() {
    return { url: '/chat', props: sharedProps };
}

export function Head() {
    return null;
}

export function Link({ href, children, as, method, ...props }: { href: string; children: ReactNode; as?: string; method?: string; className?: string }) {
    if (as === 'button') {
        return (
            <button type="button" data-method={method} {...props}>
                {children}
            </button>
        );
    }

    return (
        <a href={href} {...props}>
            {children}
        </a>
    );
}
