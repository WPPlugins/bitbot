/** The three copy fields for one language: Chat title, Welcome message, Suggested questions. */
import { useEffect, useState } from 'react'
import type { AdminSettings } from '@/api/types'
import { SettingField } from './SettingField'
import { CharCounter, LIMITS } from './FieldCounter'
import { ChipPicker } from './ChipPicker'
import { QuestionChips } from './QuestionChips'
import { useSaveWidgetCopy } from '@/hooks/useSaveWidgetCopy'
import { rowFor, TRANSLATED_LIMITS } from '@/lib/widgetCopy'


interface SiteProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
  code: string
}

/** The site language: the base settings, saved as you type. */
export function SiteLanguageFields({ settings, save, code }: SiteProps) {
  return (
    <>
      <SettingField label="Chat title">
        <input
          className="bb-input"
          lang={code}
          dir="auto"
          value={settings.widgetTitle}
          maxLength={LIMITS.widgetTitle}
          onChange={(e) => save({ widgetTitle: e.target.value })}
          placeholder={settings.widgetDefaults?.title}
        />
        <CharCounter value={settings.widgetTitle} max={LIMITS.widgetTitle} />
      </SettingField>
      <SettingField label="Welcome message">
        <textarea
          className="bb-input bb-textarea"
          rows={2}
          lang={code}
          dir="auto"
          value={settings.welcomeMessage}
          maxLength={LIMITS.welcomeMessage}
          onChange={(e) => save({ welcomeMessage: e.target.value })}
          placeholder={settings.widgetDefaults?.welcome}
        />
        <CharCounter value={settings.welcomeMessage} max={LIMITS.welcomeMessage} />
      </SettingField>
      <SettingField label="Input hint">
        <input
          className="bb-input"
          lang={code}
          dir="auto"
          value={settings.placeholderText}
          maxLength={LIMITS.placeholderText}
          onChange={(e) => save({ placeholderText: e.target.value })}
          placeholder={settings.widgetDefaults?.placeholder}
        />
        <CharCounter value={settings.placeholderText} max={LIMITS.placeholderText} />
      </SettingField>
      <SettingField label="Suggested questions">
        <ChipPicker chips={[...settings.suggestedQuestions]} onChange={(chips) => save({ suggestedQuestions: chips })} />
      </SettingField>
    </>
  )
}

/** Any other language: a row of widgetCopy, saved on blur (which makes it the owner's). */
export function TranslatedFields({ settings, code }: { settings: AdminSettings; code: string }) {
  const stored = rowFor(settings.widgetCopy, code)
  const saveRow = useSaveWidgetCopy()
  const [title, setTitle] = useState(stored.title)
  const [welcome, setWelcome] = useState(stored.welcome)
  const [placeholder, setPlaceholder] = useState(stored.placeholder)

  // Follow the stored value when it changes from outside (a new translation).
  useEffect(() => setTitle(stored.title), [stored.title])
  useEffect(() => setWelcome(stored.welcome), [stored.welcome])
  useEffect(() => setPlaceholder(stored.placeholder), [stored.placeholder])

  const commit = () => {
    const fields = {
      ...(title !== stored.title ? { title } : {}),
      ...(welcome !== stored.welcome ? { welcome } : {}),
      ...(placeholder !== stored.placeholder ? { placeholder } : {}),
    }
    if (Object.keys(fields).length > 0) saveRow.mutate({ code, fields })
  }

  return (
    <>
      <SettingField label="Chat title">
        <input
          className="bb-input"
          lang={code}
          dir="auto"
          value={title}
          maxLength={TRANSLATED_LIMITS.title}
          onChange={(e) => setTitle(e.target.value)}
          onBlur={commit}
        />
        <CharCounter value={title} max={TRANSLATED_LIMITS.title} />
      </SettingField>
      <SettingField label="Welcome message">
        <textarea
          className="bb-input bb-textarea"
          rows={2}
          lang={code}
          dir="auto"
          value={welcome}
          maxLength={TRANSLATED_LIMITS.welcome}
          onChange={(e) => setWelcome(e.target.value)}
          onBlur={commit}
        />
        <CharCounter value={welcome} max={TRANSLATED_LIMITS.welcome} />
      </SettingField>
      <SettingField label="Input hint">
        <input
          className="bb-input"
          lang={code}
          dir="auto"
          value={placeholder}
          maxLength={TRANSLATED_LIMITS.placeholder}
          onChange={(e) => setPlaceholder(e.target.value)}
          onBlur={commit}
        />
        <CharCounter value={placeholder} max={TRANSLATED_LIMITS.placeholder} />
      </SettingField>
      <SettingField label="Suggested questions">
        <QuestionChips
          value={stored.questions}
          lang={code}
          onCommit={(questions) => saveRow.mutate({ code, fields: { questions } })}
        />
      </SettingField>
    </>
  )
}
