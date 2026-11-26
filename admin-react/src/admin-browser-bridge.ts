import { AdminBrowserController, AdminBrowserError, parseAdminCommand } from './components/integrations/adminBrowserController'

// Runs inside the admin document, including editors isolated from the parent
// by Document-Isolation-Policy. No policy headers are weakened.
const config = (window as unknown as { bitbotAdminBridge?: { adminBase: string } }).bitbotAdminBridge
if (config && window.parent !== window) {
  const origin = new URL(config.adminBase).origin
  const controller = new AdminBrowserController(null as unknown as HTMLIFrameElement, config.adminBase, document)
  const seen = new Set<string>()
  const requests = new Map<string, AbortController>()
  window.addEventListener('message', async event => {
    if (event.origin !== origin || event.source !== window.parent) return
    const data = event.data
    if (!data || typeof data.id !== 'string' || !/^[a-f0-9-]{36}$/.test(data.id)) return
    const reply = (value: Record<string, unknown>) => window.parent.postMessage({ ...value, id: data.id }, origin)
    if (data.type === 'bitbot-admin-hello') {
      reply({ type: 'bitbot-admin-ready' }); return
    }
    if (data.type === 'bitbot-admin-cancel') { requests.get(data.id)?.abort(); return }
    if (data.type !== 'bitbot-admin-command' || seen.has(data.id)) return
    // Commands arrive only after the host's Manual/Auto gate. This bridge
    // trusts same-origin admin scripts, just as the WordPress admin does.
    if (requests.size || seen.size >= 10000) {
      reply({ type: 'bitbot-admin-result', error: 'Admin controller is busy. Read the screen again.', outcome: 'transport_disconnected', dispatched: false }); return
    }
    seen.add(data.id)
    const abort = new AbortController()
    requests.set(data.id, abort)
    try {
      const command = parseAdminCommand(data.command || {})
      if (command.action === 'open') throw new AdminBrowserError('Navigation belongs to the parent controller', 'transport_disconnected')
      const result = await controller.run(command, abort.signal)
      reply({ type: 'bitbot-admin-result', result })
    } catch (error) {
      // The typed outcome crosses the frame boundary with what the agent needs next.
      const typed = error instanceof AdminBrowserError
      reply({ type: 'bitbot-admin-result', error: error instanceof Error ? error.message : 'Admin action failed',
        outcome: typed ? error.outcome : 'action_failed', dispatched: typed ? error.dispatched : true, detail: typed ? error.detail : {} })
    } finally { requests.delete(data.id) }
  })
}
