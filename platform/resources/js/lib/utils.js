import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Merge Tailwind class lists, later classes winning over earlier ones.
 * Same helper shadcn-vue / Inspira UI components expect, so components copied
 * from those libraries into Components/ui work without changes.
 */
export function cn(...inputs) {
    return twMerge(clsx(inputs));
}
