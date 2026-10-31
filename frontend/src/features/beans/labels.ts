export const ROAST_LABELS: Record<string, string> = {
  light: 'Light',
  light_medium: 'Light-Med',
  medium: 'Medium',
  medium_dark: 'Med-Dark',
  dark: 'Dark',
}

/** Formats a unix timestamp as a short relative age, matching the old feed. */
export function relativeAge(timestamp: number): string {
  const seconds = Math.max(0, Math.floor(Date.now() / 1000) - timestamp)
  if (seconds < 60) return 'just now'
  const units: [number, string][] = [
    [60, 'min'],
    [3600, 'hr'],
    [86400, 'day'],
    [604800, 'week'],
  ]
  for (let index = units.length - 1; index >= 0; index -= 1) {
    const [size, name] = units[index]
    const amount = Math.floor(seconds / size)
    if (amount >= 1) return `${amount} ${name}${amount === 1 ? '' : 's'} ago`
  }
  return 'just now'
}
