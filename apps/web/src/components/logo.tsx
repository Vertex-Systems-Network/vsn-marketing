import Image from 'next/image';

/** Owner logo mark (Figma `Logo/Mark` 47:10). Decorative next to the visible product name. */
export function Logo({ size = 26 }: { size?: number }) {
    return <Image src="/logo-mark.png" alt="" width={size} height={size} priority className="shrink-0 rounded-[6px]" />;
}
