/**
 * Preset avatars shipped with the plugin under assets/avatars/ (set designed by
 * Codex, 2026-10-31). File names are part of the URL and the URL is unversioned,
 * so a redesign ships under NEW names rather than overwriting these.
 */
export interface AvatarPreset {
  file: string
  label: string
}

/** One flat list, in picker order: professional, fun, animated. */
export const AVATAR_PRESETS: readonly AvatarPreset[] = [
  { file: 'accord.svg', label: 'Accord, a speech mark with a brass seal' },
  { file: 'canopy.svg', label: 'Canopy, a pair of leaves' },
  { file: 'beacon.svg', label: 'Beacon, a four-way guide' },
  { file: 'pip-hello.svg', label: 'Pip, a pocket robot' },
  { file: 'fig-blink.svg', label: 'Fig, a fox' },
  { file: 'mochi-bob.svg', label: 'Mochi, a soft companion' },
]
// The still versions (pip.svg, fig.svg, mochi.svg) stay on disk for sites that
// picked them, but the picker shows the animated ones only: side by side the
// two rows looked identical.

/** Full URL of a preset. `assetsUrl` ends with "assets/". */
export function avatarPresetUrl(assetsUrl: string | undefined, file: string): string {
  const base = assetsUrl ?? ''
  return `${base.endsWith('/') || base === '' ? base : `${base}/`}avatars/${file}`
}

/** True when the URL points at one of the shipped presets. */
export function isPresetAvatarUrl(url: string | undefined | null): boolean {
  if (!url) return false
  const file = url.split('?')[0].split('/avatars/')[1]
  return !!file && AVATAR_PRESETS.some((p) => p.file === file)
}
