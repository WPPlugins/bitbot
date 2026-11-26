/**
 * Chatbot tab: a status bar, then two sub-tabs.
 *
 *   Widget  — welcome & languages, appearance, calls & WhatsApp, visibility &
 *             prompts, with a sticky appearance preview beside the cards
 *   Replies — reply instructions (tone and style, topics to avoid)
 *
 * Every field saves as you type; the status bar carries the real save state.
 * The lead notification email lives on the Leads tab.
 */
import { useState } from 'react'
import { PhonePreview } from '@/components/chatbot/PhonePreview'
import { AppearanceCard } from '@/components/chatbot/AppearanceCard'
import { CallsCard } from '@/components/chatbot/CallsCard'
import { LanguagesCard } from '@/components/chatbot/LanguagesCard'
import { PrechatGateCard } from '@/components/chatbot/PrechatGateCard'
import { RepliesPanel } from '@/components/chatbot/RepliesPanel'
import { StatusBar } from '@/components/chatbot/StatusBar'
import { VisibilityCard } from '@/components/chatbot/VisibilityCard'
import {
  AvatarCropModal,
  parseAvatarTransform,
  type AvatarTransform,
} from '@/components/chatbot/AvatarCropModal'
import { describeLanguage, useLanguageList } from '@/hooks/useLanguageList'
import { useAdminSettings } from '@/hooks/useAdminSettings'
import { useVoiceCalls } from '@/hooks/useVoiceCalls'
import { useChatbotSubtab, type ChatbotSubtab } from '@/hooks/useChatbotSubtab'
import { previewCopy } from '@/lib/widgetCopy'
import type { AdminSettings } from '@/api/types'

const SUBTABS: { key: ChatbotSubtab; label: string }[] = [
  { key: 'widget', label: 'Widget' },
  { key: 'replies', label: 'Replies' },
]

export function Chatbot() {
  const { settings, isLoading, save, isSaving, saveFailed, retry } = useAdminSettings()
  const [sub, setSub] = useChatbotSubtab()
  const [croppingUrl, setCroppingUrl] = useState<string | null>(null)

  if (isLoading || !settings) {
    return <div className="bb-skeleton" style={{ height: 480, marginTop: 24, borderRadius: 12 }} />
  }

  return (
    <>
      <StatusBar hideOnPages={settings.hideOnPages} isSaving={isSaving} saveFailed={saveFailed} onRetry={retry} />
      <nav className="bb-subtab-strip" role="tablist" aria-label="Chatbot sub-sections">
        {SUBTABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={sub === t.key}
            className={`bb-subtab ${sub === t.key ? 'bb-subtab-active' : ''}`}
            onClick={() => setSub(t.key)}
          >
            {t.label}
          </button>
        ))}
      </nav>

      {sub === 'widget' && <WidgetPanel settings={settings} save={save} onCrop={setCroppingUrl} />}
      {sub === 'replies' && <RepliesPanel settings={settings} save={save} />}

      {croppingUrl && (
        <AvatarCropModal
          imageUrl={croppingUrl}
          initial={parseAvatarTransform(settings.avatarTransform)}
          onCancel={() => setCroppingUrl(null)}
          onSave={(t: AvatarTransform) => {
            save({ avatarTransform: JSON.stringify(t) })
            setCroppingUrl(null)
          }}
        />
      )}
    </>
  )
}

interface WidgetPanelProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
  onCrop: (url: string) => void
}

function WidgetPanel({ settings, save, onCrop }: WidgetPanelProps) {
  const list = useLanguageList(settings)
  // The voice switch flips its own query first (optimistic); the preview
  // follows that, so the Call button appears the moment the switch is clicked.
  const voice = useVoiceCalls()
  const voiceCallsEnabled = voice.data?.enabled ?? settings.voiceCallsEnabled
  // One selected language drives both the editor and the preview.
  const [picked, setPicked] = useState(list.site)
  const lang = list.rows.some((r) => r.code === picked && r.on) ? picked : list.site
  const openCrop = () => settings.avatarUrl && onCrop(settings.avatarUrl)

  return (
    <div className="bb-chatbot-grid">
      <div className="bb-chatbot-main">
        <LanguagesCard settings={settings} save={save} list={list} lang={lang} onLang={setPicked} />
        <PrechatGateCard />
        <AppearanceCard settings={settings} save={save} onAdjustCrop={openCrop} onUploaded={onCrop} />
        <CallsCard settings={settings} save={save} />
        <VisibilityCard settings={settings} save={save} />
      </div>
      {/* Under 1100px the grid stacks, so the preview sits below the cards. */}
      <aside className="bb-chatbot-aside">
        <PhonePreview
          // previewCopy returns the whole settings for the site language, so
          // the live voice flag goes LAST or the stale saved one wins.
          settings={{ ...settings, ...previewCopy(settings, lang, list.site), voiceCallsEnabled }}
          lang={{
            value: lang,
            options: list.rows.filter((r) => r.on).map((r) => ({ code: r.code, name: describeLanguage(r.code).name })),
            onChange: setPicked,
          }}
        />
      </aside>
    </div>
  )
}
