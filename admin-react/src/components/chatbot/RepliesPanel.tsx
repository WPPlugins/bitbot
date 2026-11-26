/** Replies tab: one card of reply instructions, and a quiet note where the preview would be. */
import type { AdminSettings } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { SettingField } from './SettingField'

interface RepliesPanelProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
}

export function RepliesPanel({ settings, save }: RepliesPanelProps) {
  return (
    <div className="bb-chatbot-grid">
      <div className="bb-chatbot-main">
        <Card title="Reply instructions" subtitle="How the assistant writes and what it leaves alone.">
          <div className="bb-stack">
            <SettingField label="Tone and style" help="Describe how the assistant should write.">
              <textarea
                className="bb-input bb-textarea"
                rows={4}
                value={settings.customInstructions ?? ''}
                placeholder="be friendly, lean concise, always recommend booking a demo for pricing questions"
                maxLength={2000}
                onChange={(e) => save({ customInstructions: e.target.value })}
              />
            </SettingField>
            <SettingField label="Topics to avoid" help="One per line. The assistant will decline these.">
              <textarea
                className="bb-input bb-textarea"
                rows={4}
                value={settings.offTopicGuardrails ?? ''}
                placeholder={'competitor product comparisons\nlegal advice\nmedical advice'}
                maxLength={2000}
                onChange={(e) => save({ offTopicGuardrails: e.target.value })}
              />
            </SettingField>
          </div>
        </Card>
      </div>
      <aside className="bb-chatbot-aside">
        <p className="bb-replies-note">
          A visual preview cannot show tone or topic handling. Try a real conversation on your site to see it.
        </p>
      </aside>
    </div>
  )
}
