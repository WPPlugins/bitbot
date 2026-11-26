/** Search the language catalogue and pick one to add. Shared by the language list. */
import { useState } from 'react'
import { searchLanguages } from '@/lib/voiceLanguages'
import { CATALOGUE } from '@/hooks/useLanguageList'

interface LanguageSearchProps {
  selected: readonly string[]
  onPick: (code: string) => void
  onClose: () => void
}

export function LanguageSearch({ selected, onPick, onClose }: LanguageSearchProps) {
  const [query, setQuery] = useState('')
  const results = searchLanguages(query, CATALOGUE, selected)
  return (
    <div className="bb-lang-search">
      <input
        type="search"
        className="bb-input"
        aria-label="Search languages"
        placeholder="Search by name or code"
        autoFocus
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose() }}
      />
      {query.trim() !== '' && (
        <ul className="bb-lang-results" aria-label="Matching languages">
          {results.length === 0 && <li className="bb-lang-none">No match</li>}
          {results.map((l) => (
            <li key={l.code}>
              <button
                type="button"
                className="bb-lang-result"
                onClick={() => { onPick(l.code); onClose() }}
              >
                <span>{l.name}</span>
                <span className="bb-lang-native" lang={l.code} dir="auto">{l.native}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
