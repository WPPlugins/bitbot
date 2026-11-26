/** Card 4: Visibility & prompts. Which pages show the widget, and the invitation bubble. */
import { useState } from 'react'
import type { AdminSettings } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { FeatureRow } from './FeatureRow'
import { SettingField } from './SettingField'
import { InvitationRow } from './InvitationRow'
import { hiddenPages, hideSummary } from '@/lib/chatbotPanels'

interface VisibilityCardProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
}

export function VisibilityCard({ settings, save }: VisibilityCardProps) {
  const [editing, setEditing] = useState(false)
  return (
    <Card title="Visibility & prompts">
      <FeatureRow
        title="Show on pages"
        description={hideSummary(hiddenPages(settings.hideOnPages))}
        action={
          <button type="button" className="bb-btn bb-btn-small" aria-expanded={editing} onClick={() => setEditing(!editing)}>
            {editing ? 'Done' : 'Edit'}
          </button>
        }
      >
        {editing && (
          <SettingField label="Pages without the widget" help="One URL path per line. Use * as a wildcard.">
            <textarea
              className="bb-input bb-textarea"
              rows={5}
              value={settings.hideOnPages ?? ''}
              placeholder={'/checkout\n/cart\n/thank-you\n/account/*'}
              maxLength={2000}
              onChange={(e) => save({ hideOnPages: e.target.value })}
            />
          </SettingField>
        )}
      </FeatureRow>
      <InvitationRow settings={settings} save={save} />
      <FeatureRow
        title="Press Enter to send"
        description="When off, Enter starts a new line and visitors click the send button."
        on={!!settings.enterToSend}
        onToggle={(next) => save({ enterToSend: next })}
        pill={settings.enterToSend ? 'On' : 'Off'}
        pillTone={settings.enterToSend ? 'on' : 'off'}
      />
      <FeatureRow
        title="Show “Powered by” link"
        description="Adds a small link to youami.ai under the chat."
        on={!!settings.poweredByEnabled}
        onToggle={(next) => save({ poweredByEnabled: next })}
        pill={settings.poweredByEnabled ? 'On' : 'Off'}
        pillTone={settings.poweredByEnabled ? 'on' : 'off'}
      />
    </Card>
  )
}
