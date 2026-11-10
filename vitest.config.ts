import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/js/test/setup.ts'],
        // Two workers keep the run well under 1 GB on a small laptop.
        pool: 'threads',
        maxWorkers: 2,
        restoreMocks: true,
    },
});
