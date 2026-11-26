/** Card 1: Welcome & languages. A language list on the left, one editor on the right. */
import type { AdminSettings } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { LanguageEditor } from './LanguageEditor'
import { LanguageList } from './LanguageList'
import type { useLanguageList } from '@/hooks/useLanguageList'

interface LanguagesCardProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
  list: ReturnType<typeof useLanguageList>
  lang: string
  onLang: (code: string) => void
}

export function LanguagesCard({ settings, save, list, lang, onLang }: LanguagesCardProps) {
  const row = list.rows.find((r) => r.code === lang) ?? list.rows[0]

  return (
    <Card title="Welcome & languages" subtitle="What visitors read first, in each language you offer.">
      <div className="bb-langs">
        <LanguageList
          rows={list.rows}
          selected={list.selected}
          current={row.code}
          onSelect={onLang}
          onToggle={list.toggle}
        />
        <LanguageEditor row={row} settings={settings} save={save} onToggle={list.toggle} />
      </div>
    </Card>
  )
}
