import { useId } from 'react';

interface BrandMarkProps {
  /** Rendered width and height in px. */
  size?: number;
  className?: string;
}

/**
 * The Youami icon (same artwork as the plugin's assets/icon.svg), inlined so
 * the admin never loads it from a remote host. Decorative: the product name
 * sits beside it wherever it is used.
 */
export function BrandMark({ size = 36, className }: BrandMarkProps) {
  // One gradient id per instance, so two marks on a page never share a def.
  const gradId = `bb-mark-${useId().replace(/:/g, '')}`;
  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 256 256"
      aria-hidden="true"
      focusable="false"
    >
      <defs>
        <linearGradient id={gradId} x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="#3B82F6" />
          <stop offset="100%" stopColor="#8B5CF6" />
        </linearGradient>
      </defs>
      <circle cx="128" cy="128" r="120" fill={`url(#${gradId})`} />
      <path
        d="M60 70 h100 a20 20 0 0 1 20 20 v60 a20 20 0 0 1 -20 20 h-60 l-25 25 v-25 h-15 a20 20 0 0 1 -20 -20 v-60 a20 20 0 0 1 20 -20z"
        fill="#fff"
      />
      <circle cx="185" cy="185" r="35" fill="#fff" />
      <circle cx="185" cy="185" r="28" fill="none" stroke="#3B82F6" strokeWidth="3" />
      <ellipse cx="185" cy="185" rx="12" ry="28" fill="none" stroke="#3B82F6" strokeWidth="2.5" />
      <line x1="157" y1="185" x2="213" y2="185" stroke="#3B82F6" strokeWidth="2.5" />
      <line x1="185" y1="157" x2="185" y2="213" stroke="#3B82F6" strokeWidth="2.5" />
      <circle cx="90" cy="115" r="8" fill="#3B82F6" />
      <circle cx="120" cy="115" r="8" fill="#8B5CF6" />
      <circle cx="150" cy="115" r="8" fill="#3B82F6" />
      <rect x="80" y="140" width="60" height="6" rx="3" fill="#E5E7EB" />
    </svg>
  );
}
