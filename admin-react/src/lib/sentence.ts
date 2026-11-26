/** Split a message into its first sentence and the rest, for a bold line with detail under it. */
export function splitFirstSentence(text: string): [string, string] {
  const at = text.indexOf('. ')
  if (at < 0) return [text, '']
  return [text.slice(0, at + 1), text.slice(at + 2)]
}
