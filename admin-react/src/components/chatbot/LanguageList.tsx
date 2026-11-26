/**
 * Compact language list: the site language first (default), then the other
 * languages with their copy state. Pick a row to edit it; "+ Add language"
 * opens the catalogue search inline.
 */
import { useState } from 'react'
import { describeLanguage } from '@/hooks/useLanguageList'
import { STATE_LABELS, type LanguageRow } from '@/lib/languageState'
import { LanguageSearch } from './LanguageSearch'

interface LanguageListProps {
  rows: LanguageRow[]
  selected: readonly string[]
  current: string
  onSelect: (code: string) => void
  onToggle: (code: string) => void
}

export function LanguageList({ rows, selected, current, onSelect, onToggle }: LanguageListProps) {
  const [adding, setAdding] = useState(false)
  return (
    <div className="bb-langlist">
      <ul aria-label="Languages">
        {rows.map((r) => {
          const lang = describeLanguage(r.code)
          return (
            <li key={r.code} className={`bb-langlist-row${r.code === current ? ' bb-langlist-row--on' : ''}${r.on ? '' : ' bb-langlist-row--off'}`}>
              <button type="button" className="bb-langlist-pick" aria-current={r.code === current} onClick={() => onSelect(r.code)}>
                <span className="bb-langlist-name" lang={r.code} dir="auto">{lang.native}</span>
                {r.isSite
                  ? <small className="bb-langlist-default">default</small>
                  : <span className={`bb-copy-pill bb-copy-pill--${r.state}`}>{STATE_LABELS[r.state]}</span>}
              </button>
              {!r.isSite && (
                <button
                  type="button"
                  className="bb-link-btn bb-langlist-act"
                  onClick={() => onToggle(r.code)}
                  aria-label={`${r.on ? (r.detected ? 'Turn off' : 'Remove') : 'Turn on'} ${lang.name}`}
                >
                  {r.on ? (r.detected ? 'Turn off' : 'Remove') : 'Turn on'}
                </button>
              )}
            </li>
          )
        })}
      </ul>
      {adding ? (
        <LanguageSearch selected={selected} onPick={onToggle} onClose={() => setAdding(false)} />
      ) : (
        <button type="button" className="bb-langlist-add" onClick={() => setAdding(true)}>+ Add language</button>
      )}
    </div>
  )
}
