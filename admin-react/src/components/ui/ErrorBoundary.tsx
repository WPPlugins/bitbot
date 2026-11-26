/**
 * ErrorBoundary — catches React rendering errors so one buggy tab can't
 * take down the whole admin SPA. Reports the error to PostHog with a
 * `boundary` tag identifying where the crash happened, and renders a
 * fallback panel with a "Copy diagnostics" button so support can ask
 * the customer for the exact stack trace.
 *
 * Wrap each tab body in <ErrorBoundary boundary="leads">…</ErrorBoundary>
 * so a crash inside one tab doesn't kill the BrandBar / TabStrip.
 */
import { Component, type ErrorInfo, type ReactNode } from 'react'
import { captureException } from '@/lib/telemetry'
import { getConfig } from '@/lib/config'

interface ErrorBoundaryProps {
  children: ReactNode
  /** Short id for telemetry — e.g. "leads", "chatbot", "root". */
  boundary: string
  /** Optional custom fallback. Defaults to the generic message panel. */
  fallback?: (err: Error, reset: () => void) => ReactNode
}

interface ErrorBoundaryState {
  err: Error | null
  diagnostics: string | null
}

export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { err: null, diagnostics: null }

  static getDerivedStateFromError(err: Error): Partial<ErrorBoundaryState> {
    return { err }
  }

  componentDidCatch(err: Error, info: ErrorInfo) {
    const cfg = (() => { try { return getConfig() } catch { return null } })()
    captureException(err, {
      boundary: this.props.boundary,
      component_stack: info.componentStack,
      plan: cfg?.plan,
      plugin_version: cfg?.pluginVersion,
    })

    // Build a copy-paste diagnostics blob the customer can send to support.
    this.setState({
      diagnostics: [
        `Youami diagnostics`,
        `------------------`,
        `Where:    ${this.props.boundary}`,
        `When:     ${new Date().toISOString()}`,
        `Plan:     ${cfg?.plan ?? 'unknown'}`,
        `Plugin:   ${cfg?.pluginVersion ?? 'unknown'}`,
        `Domain:   ${(() => { try { return new URL(cfg?.siteUrl ?? '').host } catch { return '' } })()}`,
        `URL:      ${typeof window !== 'undefined' ? window.location.href : ''}`,
        `User-agent: ${typeof navigator !== 'undefined' ? navigator.userAgent : ''}`,
        ``,
        `Error: ${err.name}: ${err.message}`,
        ``,
        `Stack:`,
        err.stack ?? '(no stack)',
        ``,
        `Component stack:`,
        info.componentStack ?? '(no component stack)',
      ].join('\n'),
    })
  }

  reset = () => this.setState({ err: null, diagnostics: null })

  copyDiagnostics = () => {
    if (!this.state.diagnostics) return
    navigator.clipboard?.writeText(this.state.diagnostics).catch(() => {
      // Fallback for no clipboard permission: open a textarea the user can copy.
      alert(this.state.diagnostics!)
    })
  }

  render() {
    if (!this.state.err) return this.props.children
    if (this.props.fallback) return this.props.fallback(this.state.err, this.reset)

    return (
      <section className="bb-card bb-errbound" role="alert">
        <header className="bb-card-header">
          <h3 className="bb-card-title">
            <span className="bb-eyebrow">Something broke</span>
            <span>This panel hit an error</span>
          </h3>
        </header>
        <div className="bb-card-body">
          <p className="bb-errbound-body">
            Don't worry — the rest of the admin is fine. The error has been
            logged automatically. You can keep using the other tabs while
            we investigate, or click "Try again" to retry this view.
          </p>
          <pre className="bb-errbound-message">{this.state.err.name}: {this.state.err.message}</pre>
          <div className="bb-errbound-actions">
            <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={this.copyDiagnostics}>
              Copy diagnostics
            </button>
            <button className="bb-btn bb-btn-primary bb-btn-small" onClick={this.reset}>
              Try again
            </button>
          </div>
        </div>
      </section>
    )
  }
}
