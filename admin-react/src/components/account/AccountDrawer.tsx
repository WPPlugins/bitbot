/**
 * AccountDrawer — right-side slide-in panel. Triggered from the BrandBar.
 * Sections:
 *   • Usage & credits (paid plans: meter, add credits, auto top-up)
 *   • Billing actions (open Stripe portal, cancel, reactivate)
 *
 * Notification email moved to Chatbot → Leads sub-tab so all lead
 * settings live together. Bot voice and guardrails moved to
 * Chatbot → Behaviour for the same reason — drawer nav retired.
 */
import { useState } from 'react'
import * as Dialog from '@radix-ui/react-dialog'
import { useSubscription, useOpenBillingPortal } from '@/hooks/useSubscription'
import { getConfig } from '@/lib/config'
import { useAdminSettings } from '@/hooks/useAdminSettings'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { CreditsPanel, creditsPanelTitle } from '@/components/billing/CreditsPanel'

interface AccountDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function AccountDrawer({ open, onOpenChange }: AccountDrawerProps) {
  const cfg = getConfig()
  const livePlan = useCurrentPlan()
  const { data: sub } = useSubscription()
  const openPortal = useOpenBillingPortal()
  const [changePlanOpen, setChangePlanOpen] = useState(false)
  const { settings, save } = useAdminSettings()

  // Portal into the Youami root so all CSS tokens (--paper-1 etc.) and
  // the :where() button reset apply to the drawer's content.
  const root = document.getElementById('bitbot-admin-root') ?? undefined

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal container={root}>
        <Dialog.Overlay className="bb-drawer-overlay" />
        <Dialog.Content className="bb-drawer" aria-describedby={undefined}>
          <header className="bb-drawer-header">
            <Dialog.Title className="bb-drawer-title">{creditsPanelTitle(livePlan)}</Dialog.Title>
            <Dialog.Close className="bb-drawer-close" aria-label="Close">✕</Dialog.Close>
          </header>

          <div className="bb-drawer-body">
            <CreditsPanel />

            {/* Billing — single Change Plan entry covers upgrade,
                downgrade, reactivate, and cancel-to-Free. Stripe portal
                link stays here for invoices + card management only. */}
            {sub && (
              <section className="bb-drawer-section">
                <span className="bb-eyebrow">Billing</span>
                <div className="bb-account-actions">
                  <button
                    type="button"
                    className="bb-btn bb-btn-primary bb-btn-small"
                    onClick={() => setChangePlanOpen(true)}
                  >
                    {livePlan === 'free' ? 'Upgrade →' : 'Change plan →'}
                  </button>
                  {livePlan !== 'free' && (
                    <button
                      className="bb-btn bb-btn-ghost bb-btn-small"
                      disabled={openPortal.isPending}
                      onClick={() => openPortal.mutate()}
                    >
                      {openPortal.isPending ? 'Opening…' : 'Manage card · view invoices →'}
                    </button>
                  )}
                </div>
              </section>
            )}

            {/* Privacy: analytics is opt-in and off until switched on. */}
            <section className="bb-drawer-section">
              <span className="bb-eyebrow">Privacy</span>
              <label className="bb-account-optin">
                <input
                  type="checkbox"
                  checked={!!settings?.analyticsEnabled}
                  onChange={(e) => save({ analyticsEnabled: e.target.checked })}
                />{' '}
                Share anonymous usage analytics
              </label>
              <p className="bb-account-help">Helps us fix problems in this admin. Off by default. Reload to apply.</p>
            </section>

            {/* Footer info */}
            <section className="bb-drawer-section bb-drawer-footer-section">
              <span className="bb-eyebrow">Site</span>
              <dl className="bb-account-meta">
                <dt>Domain</dt><dd>{(() => { try { return new URL(cfg.siteUrl).host } catch { return cfg.siteUrl } })()}</dd>
                <dt>Plugin version</dt><dd>{cfg.pluginVersion}</dd>
              </dl>
              <p className="bb-account-help">
                Questions? Email <a href="mailto:support@youami.ai">support@youami.ai</a>.
              </p>
            </section>
          </div>
          <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
