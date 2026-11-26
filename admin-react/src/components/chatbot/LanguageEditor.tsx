/** The one editor for the selected language: heading with state, actions, and its copy fields. */
import type { AdminSettings } from '@/api/types'
import { describeLanguage } from '@/hooks/useLanguageList'
import { useMarkReviewed, useRegenerateCopy } from '@/hooks/useSaveWidgetCopy'
import { STATE_LABELS, type LanguageRow } from '@/lib/languageState'
import { SiteLanguageFields, TranslatedFields } from './LanguageFields'

interface LanguageEditorProps {
  row: LanguageRow
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
  onToggle: (code: string) => void
}

export function LanguageEditor({ row, settings, save, onToggle }: LanguageEditorProps) {
  const lang = describeLanguage(row.code)
  const regenerate = useRegenerateCopy()
  const reviewed = useMarkReviewed()
  const translated = !row.isSite

  return (
    <div className="bb-langedit">
      <div className="bb-langedit-head">
        <h4 className="bb-langedit-name" lang={row.code} dir="auto">{lang.native}</h4>
        <span className={`bb-copy-pill bb-copy-pill--${row.state}`}>{STATE_LABELS[row.state]}</span>
        {translated && row.on && (
          <span className="bb-langedit-actions">
            {row.state === 'review' && (
              <button type="button" className="bb-link-btn" disabled={reviewed.isPending} onClick={() => reviewed.mutate(row.code)}>
                Mark reviewed
              </button>
            )}
            <button type="button" className="bb-link-btn" disabled={regenerate.isPending} onClick={() => regenerate.mutate(row.code)}>
              {regenerate.isPending ? 'Translating…' : 'Regenerate translation'}
            </button>
          </span>
        )}
      </div>

      {!row.on ? (
        <div className="bb-langedit-off">
          <p className="bb-field-help">{lang.name} is switched off, so visitors do not see it.</p>
          <button type="button" className="bb-btn bb-btn-small" onClick={() => onToggle(row.code)}>Turn on</button>
        </div>
      ) : row.isSite ? (
        <SiteLanguageFields key={row.code} settings={settings} save={save} code={row.code} />
      ) : (
        <TranslatedFields key={row.code} settings={settings} code={row.code} />
      )}
    </div>
  )
}
