/**
 * React entry point. Mounts into #bitbot-admin-root which the PHP
 * enqueue layer outputs on admin pages where ?bitbot_new_admin=1 is
 * present (or unconditionally after the Feature 9 hard-cutover).
 */
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import App from './App'
import { ConnectGate } from './components/connect/ConnectGate'
import { getConfig } from './lib/config'
import { ToastProvider } from './components/ui/Toast'
import { ConfirmProvider } from './components/ui/ConfirmDialog'
import { ErrorBoundary } from './components/ui/ErrorBoundary'
import { initTelemetry, captureEvent } from './lib/telemetry'

import './styles/tokens.css'
import './styles/base.css'
import './components/ui/ui.css'
import './components/plan/plan.css'
import './components/overview/overview.css'
import './components/chatbot/chatbot.css'
import './components/chatbot/chatbot-panels.css'
import './components/leads/leads.css'
import './components/conversations/conversations.css'
import './components/content/content.css'
import './components/forms/forms.css'
import './components/integrations/integrations.css'
import './components/account/account.css'
import './components/announcements/announcements.css'
import './components/billing/changeplan.css'
import './styles/polish.css'   /* keep last — overrides for reduced-motion + 320px */

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Stable default — individual hooks override staleTime per surface.
      staleTime: 60 * 1000,
      gcTime: 10 * 60 * 1000,
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
})

const root = document.getElementById('bitbot-admin-root')
if (!root) {
  // eslint-disable-next-line no-console
  console.error('[Youami] #bitbot-admin-root not found — admin SPA cannot mount')
} else {
  // Initialise telemetry FIRST so the SPA-load event and any boot
  // errors get captured.
  initTelemetry()
  captureEvent('admin_loaded', {
    initial_tab: new URL(window.location.href).searchParams.get('tab') ?? 'overview',
  })

  // wp_localize_script turns scalars into strings, so a PHP false arrives as ''
  // (and true as '1'), never as a boolean false.
  const isNotConnected = (v: unknown) => v === false || v === '' || v === '0'

  createRoot(root).render(
    <StrictMode>
      <ErrorBoundary boundary="root">
        <QueryClientProvider client={queryClient}>
          <ToastProvider>
            <ConfirmProvider>
              {isNotConnected(getConfig().connected) ? <ConnectGate /> : <App />}
            </ConfirmProvider>
          </ToastProvider>
        </QueryClientProvider>
      </ErrorBoundary>
    </StrictMode>,
  )
}
