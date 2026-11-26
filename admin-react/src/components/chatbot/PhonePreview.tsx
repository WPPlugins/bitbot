/**
 * Live preview of the chat widget as it appears on a real desktop
 * browser. Mock browser chrome + page content + chat panel anchored
 * to the configured corner with the configured offset. Updates in
 * real time as the admin edits settings.
 *
 * Pure visual mock — no widget.js iframe. Covers colours, copy, chips,
 * avatar, shape, corner radius, position, offset.
 *
 * Interactive: a segmented toggle at the top of the preview lets the
 * admin flip between "Open" (chat panel visible — what a visitor sees
 * while chatting) and "Closed" (just the FAB — what a visitor sees on
 * first page-load before they click). Clicking the close button on
 * the panel header, or the FAB itself, toggles the state too — it
 * mirrors the actual widget interaction.
 *
 * File kept as PhonePreview.tsx for import-path stability; the prior
 * mobile-frame mode was removed in 2.0.1 because the phone mockup
 * didn't communicate anything the desktop view didn't already show.
 */
import { useState, useEffect, useRef } from 'react'
import type { AdminSettings } from '@/api/types'
import { parseAvatarTransform, transformToBackground } from './AvatarCropModal'
import { isRtl } from '@/lib/languageState'
import { previewPillSize } from '@/lib/pillButton'
import { previewHeaderTools, type PreviewHeaderTools } from '@/lib/previewHeader'
import { checkWhatsAppNumber } from '@/lib/whatsappNumber'
import { useForms } from '@/hooks/useForms'
import { gateCardModel, gateCopyFor, gateRowLabel, GATE_EXAMPLE_MESSAGE, type GateCardModel, type GateRow } from '@/lib/prechatPreview'

interface PhonePreviewProps {
  settings: AdminSettings
  /** Language select: the options are the languages the site offers. */
  lang?: {
    value: string
    options: { code: string; name: string }[]
    onChange: (code: string) => void
  }
}

/** One gate field drawn the way the widget renders its type. */
function GateRowView({ row }: { row: GateRow }) {
  if (row.shape === 'radio' || row.shape === 'checkbox') {
    return (
      <fieldset className="bb-pp-gate-row bb-pp-gate-group">
        <legend>{gateRowLabel(row)}</legend>
        {row.options.map((o) => (
          <span key={o} className="bb-pp-gate-choice">
            <i className={row.shape === 'radio' ? 'bb-pp-gate-radio' : 'bb-pp-gate-check'} aria-hidden="true" />
            {o}
          </span>
        ))}
      </fieldset>
    )
  }
  return (
    <div className="bb-pp-gate-row">
      <span>{gateRowLabel(row)}</span>
      <div className={`bb-pp-gate-input${row.shape === 'textarea' ? ' bb-pp-gate-textarea' : ''}${row.shape === 'select' ? ' bb-pp-gate-select' : ''}`}>
        {row.placeholder}
      </div>
    </div>
  )
}

function ChatPanel({ settings, onClose, rtl, tools, langCode, gate }: { settings: AdminSettings; onClose?: () => void; rtl?: boolean; tools: PreviewHeaderTools; langCode?: string; gate?: GateCardModel }) {
  const primary = settings.primaryColor || '#0073aa'
  const title = settings.widgetTitle || settings.widgetDefaults?.title || ''
  const welcome = settings.welcomeMessage || settings.widgetDefaults?.welcome || ''
  const placeholder = settings.placeholderText || settings.widgetDefaults?.placeholder || ''
  const chips = settings.suggestedQuestions || []
  // 2.0.2: pinned message dropped from the Chatbot tab UI; preview no
  // longer renders the banner either, even if a legacy value persists.
  const avatarUrl = settings.avatarUrl || ''
  // Chat-header avatar is a 32×32 circle, so it uses the 'rounded'
  // transform if one was saved (legacy values render as plain cover).
  // Like the widget, the gate scrolls into view when it appears.
  const bodyRef = useRef<HTMLDivElement | null>(null)
  useEffect(() => {
    // The preview body is small, so show the whole card: scroll its heading to the top.
    const body = bodyRef.current
    const card = body?.querySelector<HTMLElement>('.bb-pp-gate')
    if (gate && body && card) body.scrollTop = Math.max(0, card.offsetTop - 6)
  }, [gate])
  const headerBg = transformToBackground(parseAvatarTransform(settings.avatarTransform), 32, 32)

  // Composer: pill-bordered input + send, at the bottom like the real
  // .bitbot-input-area.
  const composer = (
    <div className="bb-pp-composer">
      <input
        className="bb-pp-input"
        placeholder={placeholder}
        disabled
        style={{ borderColor: primary }}
      />
      <button className="bb-pp-send" style={{ background: primary }} aria-label="Send">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="white" strokeWidth="2">
          <line x1="22" y1="2" x2="11" y2="13" />
          <polygon points="22 2 15 22 11 13 2 9 22 2" />
        </svg>
      </button>
    </div>
  )

  return (
    <div className="bb-pp-screen" dir={rtl ? 'rtl' : undefined}>
      {/* Header: optional avatar (when configured) + title + minimize (—).
          The avatar slot mirrors the real widget — when there's an
          uploaded image, we show a small circular crop of it before
          the title so visitors know who they're talking to. */}
      <header className="bb-pp-header" style={{ background: primary }}>
        {avatarUrl && (
          <span className="bb-pp-header-avatar">
            {headerBg ? (
              <span
                className="bb-pp-header-avatar-bg"
                style={{
                  backgroundImage: `url(${avatarUrl})`,
                  backgroundSize: headerBg.backgroundSize,
                  backgroundPosition: headerBg.backgroundPosition,
                  backgroundRepeat: 'no-repeat',
                }}
              />
            ) : (
              <img src={avatarUrl} alt="" className="bb-pp-header-avatar-img" />
            )}
          </span>
        )}
        <span className="bb-pp-header-title">{title}</span>
        {/* The same tools the real header shows, under the same rules
            (lib/previewHeader.ts): language pill, Call, WhatsApp. */}
        {tools.lang && (
          <span className="bb-pp-header-tool bb-pp-header-lang" style={{ color: primary }} aria-label="Language">
            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <circle cx="12" cy="12" r="10" />
              <line x1="2" y1="12" x2="22" y2="12" />
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
            <span className="bb-pp-header-lang-code">{(langCode || '').toUpperCase()}</span>
          </span>
        )}
        {tools.call && (
          <span className="bb-pp-header-tool bb-pp-header-call" aria-label="Voice call">
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
            </svg>
          </span>
        )}
        {tools.whatsapp && (
          <span className="bb-pp-header-tool bb-pp-header-wa" aria-label="WhatsApp">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
              <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.7 8.23-8.23 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.29z" />
            </svg>
          </span>
        )}
        <button
          className="bb-pp-minimize"
          aria-label="Minimize"
          type="button"
          onClick={onClose}
          title="Minimize (preview)"
        >
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2">
            <line x1="5" y1="12" x2="19" y2="12" />
          </svg>
        </button>
      </header>

      <div className="bb-pp-body" ref={bodyRef}>
        {/* Bot welcome message — plain grey bubble, no avatar, left-aligned. */}
        <div className="bb-pp-message bb-pp-message-bot">
          <div className="bb-pp-bubble bb-pp-bubble-bot">{welcome}</div>
        </div>

        {gate && (
          <>
            <div className="bb-pp-message bb-pp-message-user">
              <div className="bb-pp-bubble bb-pp-bubble-user bb-pp-pending" style={{ background: primary }}>
                {GATE_EXAMPLE_MESSAGE}
                <div className="bb-pp-pending-note">{gate.pendingLabel}</div>
              </div>
            </div>
            <div className="bb-pp-gate">
              <div className="bb-pp-gate-head">{gate.heading}</div>
              {gate.rows.map((r) => <GateRowView key={r.name} row={r} />)}
              <div className="bb-pp-gate-actions">
                <span className="bb-pp-gate-submit" style={{ background: primary }}>{gate.submitLabel}</span>
                <span className="bb-pp-gate-cancel">{gate.backLabel}</span>
              </div>
            </div>
          </>
        )}

        {!gate && chips.length > 0 && (
          <div className="bb-pp-chips">
            {chips.slice(0, 4).map((chip, i) => (
              <button
                type="button"
                key={i}
                className="bb-pp-chip"
                style={{ borderColor: primary, color: primary }}
              >
                {chip.label || '…'}
              </button>
            ))}
          </div>
        )}
      </div>

      {composer}

      {/* Branding footer: only when the owner opted in, matching the real widget. */}
      {settings.poweredByEnabled && (
        <div className="bb-pp-branding">
          Powered by <strong>Youami</strong>
        </div>
      )}
    </div>
  )
}

/**
 * The FAB renders in both Open and Closed modes — matching the real
 * widget, which never hides the launcher even while the chat panel is
 * open. The icon swaps: chat-bubble (or avatar) when closed, ✕ when
 * open. Clicking it toggles state in the preview.
 */
function FabIcon({
  shape,
  primary,
  avatarUrl,
  avatarBg,
  showCloseIcon,
  cornerRadius,
  pillHeight,
  label,
  positionRight,
}: {
  shape: AdminSettings['widgetShape']
  primary: string
  avatarUrl?: string
  /** Pre-computed { backgroundSize, backgroundPosition } from the
   *  saved transform, or null to fall back to plain object-fit cover
   *  via an <img>. Round launcher only; the pill takes no crop. */
  avatarBg: { backgroundSize: string; backgroundPosition: string } | null
  showCloseIcon: boolean
  cornerRadius: number
  pillHeight: number
  /** Chat title, shown beside the avatar on the pill. */
  label: string
  /** Launcher corner: the pill avatar sits on the edge side. */
  positionRight: boolean
}) {
  const scaledRadius = Math.round((cornerRadius / 56) * 36)

  if (shape === 'pill') {
    const { w, h, avatar, font } = previewPillSize(pillHeight)
    const hasAvatar = !!avatarUrl
    // The avatar circle sits on the side nearest the screen edge.
    return (
      <div
        className="bb-pp-fab-pill-row"
        style={{ height: h, flexDirection: positionRight ? 'row' : 'row-reverse' }}
      >
        <div
          className="bb-pp-fab bb-pp-fab-pill"
          style={{
            background: primary,
            // Open: the text pill folds into a round close button of the pill height.
            borderRadius: showCloseIcon ? '50%' : `${Math.min(scaledRadius, Math.round(h / 2))}px`,
            minWidth: showCloseIcon ? h : w,
            height: h,
            padding: showCloseIcon ? 0 : '0 10px',
            width: showCloseIcon ? h : undefined,
          }}
        >
          {showCloseIcon ? (
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="white" strokeWidth="2">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          ) : (
            <span className="bb-pp-fab-pill-label" style={{ fontSize: font }}>{label}</span>
          )}
        </div>
        {hasAvatar && !showCloseIcon && (
          <img src={avatarUrl} alt="" className="bb-pp-fab-pill-avatar" style={{ width: avatar, height: avatar }} />
        )}
      </div>
    )
  }

  const radius =
    shape === 'circle' ? '50%' :
    shape === 'square' ? '2px' :
    shape === 'hexagon' ? '50%' :
                          `${scaledRadius}px`

  const inner = showCloseIcon ? (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="white" strokeWidth="2">
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  ) : avatarUrl ? (
    avatarBg ? (
      <span
        className="bb-pp-fab-avatar"
        style={{
          backgroundImage: `url(${avatarUrl})`,
          backgroundSize: avatarBg.backgroundSize,
          backgroundPosition: avatarBg.backgroundPosition,
          backgroundRepeat: 'no-repeat',
        }}
      />
    ) : (
      <img src={avatarUrl} alt="" className="bb-pp-fab-avatar" />
    )
  ) : (
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="white" strokeWidth="1.5">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  )
  return (
    <div className="bb-pp-fab" style={{ background: primary, borderRadius: radius }}>
      {inner}
    </div>
  )
}

/**
 * Preview offsets are 1:1 with the live site, capped so a 200 px setting
 * cannot push the widget out of the frame. This USED to be /3 with a 6 px
 * floor — which collapsed the whole realistic range into a 6–13 px band, so
 * an owner dialing X or Y watched the icon not move and concluded the
 * setting was broken (it worked fine on the live site; the preview lied).
 * 0 must render flush to the corner, so there is no floor.
 */
function scaleOffset(realPx: number): number {
  return Math.max(0, Math.min(80, Math.round(realPx)))
}

export function PhonePreview({ settings, lang }: PhonePreviewProps) {
  const [mode, setMode] = useState<'open' | 'closed' | 'first'>('open')
  const { data: forms } = useForms()
  const defaultForm = forms?.find((f) => f.isDefault)
  const gateOn = !!defaultForm?.chatGateEnabled
  // Gate turned off while previewing it: fall back to the open panel.
  const shownMode = mode === 'first' && !gateOn ? 'open' : mode
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop')
  // The nudge "?" in the Appearance card fires this event; the preview then
  // PLAYS the behaviour — flip closed, pop the callout beside the FAB, let it
  // fade — instead of the card describing it in prose.
  const [nudgeMsg, setNudgeMsg] = useState<string | null>(null)
  // The demo also BUZZES the launcher, as the live widget does, and scrolls
  // the preview into view — on a narrow screen the preview stacks below the
  // cards, so without the scroll the demo plays somewhere the admin cannot see.
  const [ringing, setRinging] = useState(false)
  const previewRef = useRef<HTMLDivElement | null>(null)
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined
    const onDemo = (e: Event) => {
      const message = (e as CustomEvent<{ message?: string }>).detail?.message
      setMode('closed')
      setNudgeMsg(message || "Need a hand? I'm here to help.")
      setRinging(true)
      previewRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      if (timer) clearTimeout(timer)
      timer = setTimeout(() => { setNudgeMsg(null); setRinging(false) }, 5000)
    }
    window.addEventListener('bb-preview-nudge', onDemo)
    return () => { window.removeEventListener('bb-preview-nudge', onDemo); if (timer) clearTimeout(timer) }
  }, [])
  const positionRight = settings.widgetPosition !== 'bottom-left'
  const offsetX = scaleOffset(settings.widgetOffsetX ?? 20)
  const offsetY = scaleOffset(settings.widgetOffsetY ?? 20)
  // The launcher's box in the preview, so the invitation bubble can sit beside it.
  const fabSize = (() => {
    if (settings.widgetShape !== 'pill') return { w: 36, h: 36 }
    const pill = previewPillSize(settings.pillHeight ?? 28)
    return { w: pill.w + (settings.avatarUrl ? pill.avatar + 6 : 0), h: pill.h }
  })()
  const primary = settings.primaryColor || '#0073aa'
  const tools = previewHeaderTools({
    voiceOn: !!settings.voiceCallsEnabled,
    whatsappOn: !!settings.whatsappEnabled,
    whatsappNumberValid: (() => { const c = checkWhatsAppNumber(settings.whatsappNumber ?? ''); return c.ok && c.value !== '' })(),
    languageCount: lang ? lang.options.length : 1,
  })

  return (
    <div ref={previewRef} className="bb-phone-preview">
      <div className="bb-pp-header-bar">
        <div className="bb-pp-mode-toggle" role="tablist" aria-label="Preview device">
          {(['desktop', 'mobile'] as const).map((d) => (
            <button
              key={d}
              type="button"
              role="tab"
              aria-selected={device === d}
              className={`bb-pp-mode-btn ${device === d ? 'bb-pp-mode-btn-active' : ''}`}
              onClick={() => setDevice(d)}
            >{d === 'desktop' ? 'Desktop' : 'Mobile'}</button>
          ))}
        </div>
        <div className="bb-pp-mode-toggle" role="tablist" aria-label="Preview state">
          <button
            type="button"
            role="tab"
            aria-selected={shownMode === 'open'}
            className={`bb-pp-mode-btn ${shownMode === 'open' ? 'bb-pp-mode-btn-active' : ''}`}
            onClick={() => setMode('open')}
          >Open</button>
          <button
            type="button"
            role="tab"
            aria-selected={shownMode === 'closed'}
            className={`bb-pp-mode-btn ${shownMode === 'closed' ? 'bb-pp-mode-btn-active' : ''}`}
            onClick={() => setMode('closed')}
          >Closed</button>
          <button
            type="button"
            role="tab"
            aria-selected={shownMode === 'first'}
            disabled={!gateOn}
            title={gateOn ? undefined : 'Turn on the pre-chat form to preview it'}
            className={`bb-pp-mode-btn ${shownMode === 'first' ? 'bb-pp-mode-btn-active' : ''}`}
            onClick={() => setMode('first')}
          >First reply</button>
        </div>
        {lang && lang.options.length > 1 && (
          <select
            className="bb-pp-lang-select"
            aria-label="Preview language"
            value={lang.value}
            onChange={(e) => lang.onChange(e.target.value)}
          >
            {lang.options.map((o) => <option key={o.code} value={o.code}>{o.name}</option>)}
          </select>
        )}
      </div>

      <div className={`bb-pp-desktop ${device === 'mobile' ? 'bb-pp-desktop--mobile' : ''}`}>
        <div className="bb-pp-browser-chrome">
          <span className="bb-pp-browser-dot" style={{ background: '#FF5F57' }} />
          <span className="bb-pp-browser-dot" style={{ background: '#FEBC2E' }} />
          <span className="bb-pp-browser-dot" style={{ background: '#28C840' }} />
          <span className="bb-pp-browser-addr">
            {(() => { try { return new URL(window.bitbotAdminConfig?.siteUrl ?? '').host } catch { return 'your-site.com' } })()}
          </span>
        </div>
        <div className="bb-pp-page">
          <div className="bb-pp-page-headline" />
          <div className="bb-pp-page-line" style={{ width: '80%' }} />
          <div className="bb-pp-page-line" style={{ width: '92%' }} />
          <div className="bb-pp-page-line" style={{ width: '65%' }} />
          <div className="bb-pp-page-line" style={{ width: '78%' }} />
        </div>

        {/* When open: panel sits ABOVE the FAB, gap matches real widget
            (`.bitbot-chat { bottom: 70px }` while FAB sits at 0). In the
            scaled preview the FAB is 36px so panel offsets by ~44px. */}
        {shownMode !== 'closed' && (
          <div
            className="bb-pp-desktop-panel"
            style={{
              bottom: offsetY + 44,
              [positionRight ? 'right' : 'left']: offsetX,
              // Propagate slider value to every child via CSS custom
              // property; .bb-pp-screen + .bb-pp-bubble read it.
              '--bb-pp-radius': `${settings.cornerRadius ?? 16}px`,
            } as React.CSSProperties}
          >
            <ChatPanel settings={settings} onClose={() => setMode('closed')} rtl={lang ? isRtl(lang.value) : false} tools={tools} langCode={lang?.value} gate={shownMode === 'first' ? gateCardModel(defaultForm, gateCopyFor(settings.gateCopy, lang?.value, Object.keys(settings.gateCopy ?? {})[0])) : undefined} />
          </div>
        )}

        {nudgeMsg && shownMode === 'closed' && (
          <div
            className={`bb-pp-nudge ${positionRight ? 'bb-pp-nudge-from-right' : 'bb-pp-nudge-from-left'}`}
            style={{
              // Beside the launcher, centred on it, as on the live site.
              bottom: offsetY + fabSize.h / 2,
              translate: '0 50%',
              [positionRight ? 'right' : 'left']: offsetX + fabSize.w + 10,
            } as React.CSSProperties}
          >
            <span className="bb-pp-nudge-text">{nudgeMsg}</span>
          </div>
        )}

        {/* FAB always present — matches real widget. */}
        <button
          type="button"
          className={`bb-pp-desktop-fab-wrap bb-pp-desktop-fab-button${ringing ? ' bb-pp-fab-buzz' : ''}`}
          onClick={() => setMode(shownMode === 'closed' ? 'open' : 'closed')}
          aria-label={shownMode !== 'closed' ? 'Close chat (preview)' : 'Open chat (preview)'}
          style={{
            bottom: offsetY,
            [positionRight ? 'right' : 'left']: offsetX,
          } as React.CSSProperties}
        >
          <FabIcon
            shape={settings.widgetShape}
            primary={primary}
            avatarUrl={settings.avatarUrl || undefined}
            avatarBg={transformToBackground(parseAvatarTransform(settings.avatarTransform), 36, 36)}
            label={settings.widgetTitle || settings.widgetDefaults?.title || ''}
            showCloseIcon={shownMode !== 'closed'}
            cornerRadius={settings.cornerRadius ?? 16}
            pillHeight={settings.pillHeight ?? 28}
            positionRight={positionRight}
          />
        </button>
      </div>
      <p className="bb-pp-caption">Appearance preview.</p>
    </div>
  )
}
