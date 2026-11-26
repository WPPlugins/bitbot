/**
 * AgentLockedPitch — the Agent tab when the site is NOT on Pro+.
 *
 * A tiny game: the Youami mascot delivers the pitch in a retro text box, NPC
 * style. It opens honest ("happy with your visitor bot? then you don't need
 * me"), then drops a REAL finding scanned from this site (image-less posts,
 * stale content, unpublished drafts — computed in PHP, passed via the bootstrap
 * as `agentTeaser`), then makes the ask. The mascot changes expression per beat.
 * One CTA (Change Plan). No fake faded UI.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { MouseEvent as ReactMouseEvent } from 'react'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { useOverviewInsights } from '@/hooks/useOverview'
import { useAgentHome, useStartSiteScan } from '@/hooks/useAgentHome'
import { getConfig } from '@/lib/config'

const POSE_SEQ = ['hi', 'scan', 'aha', 'wink'] as const
type Pose = (typeof POSE_SEQ)[number] | 'coding'

// Used only when PHP found nothing site-specific to dangle (brand-new site).
const GENERIC_FINDINGS = [
  'SCAN: your bot answers questions all day but never turns them into content. I would.',
  'SCAN: your widget is reactive. Growth needs someone proactive, and that is me.',
]

// Bold a couple of known tokens. Input is our own copy plus PHP-sanitised,
// integer-substituted findings — no user/visitor HTML ever reaches here.
function bold(s: string): string {
  return s.replace(/^SCAN:/, '<b>SCAN:</b>').replace(/\$49/, '<b>$49</b>').replace(/GROW/, '<b>GROW</b>')
}

export function AgentLockedPitch() {
  const cfg = getConfig()
  const assets = cfg.assetsUrl ?? ''
  const poseUrl = (p: Pose): string => `${assets}mascot/bitbot-${p}.webp`
  const { data: insights } = useOverviewInsights()
  // The REAL audit, if this site has had its one free scan. Everything else the
  // mascot dangles is a cheap heuristic — image-less posts, stale dates, unread
  // drafts — computed in PHP without ever looking at the site. These are what
  // the agent actually found, so they outrank all of it.
  const { data: home } = useAgentHome()
  const startScan = useStartSiteScan()
  // While this is true the terminal belongs to the scan, not to the pitch.
  const isScanning = home?.scanStatus === 'running'

  // Merge both sources and let the mascot lead with the MOST compelling one:
  // assistant-level coverage gaps (real visitor demand the bot is failing to
  // answer) score highest, then the WP-data findings (image-less posts, stale
  // content, drafts). "what else" rotates down the ranked list.
  const ranked = useMemo<string[]>(() => {
    const items: Array<{ t: string; score: number }> = []
    for (const g of (insights?.coverageGaps ?? []).slice(0, 3)) {
      if (!g.topic) continue
      const who = g.count > 1 ? `${g.count} visitors` : 'A visitor'
      items.push({
        t: `SCAN: ${who} asked about "${g.topic}" and the bot came up blank. That is a post writing itself.`,
        score: 200 + g.count,
      })
    }
    // A real audit finding beats any heuristic: it was OBSERVED, it carries
    // evidence, and it is the thing the agent would actually fix.
    if (home?.finding) {
      items.push({ t: `SCAN: ${home.finding.title}`, score: 1000 })
    }
    ;(cfg.agentTeaser ?? []).forEach((t, i) => items.push({ t, score: 100 - i * 5 }))
    const seen = new Set<string>()
    const out = items
      .sort((a, b) => b.score - a.score)
      .map((x) => x.t)
      .filter((t) => (seen.has(t) ? false : (seen.add(t), true)))
    return out.length ? out : GENERIC_FINDINGS
  }, [insights, home])

  const [changePlanOpen, setChangePlanOpen] = useState(false)
  const [pose, setPose] = useState<Pose>('hi')
  const [poseVisible, setPoseVisible] = useState(true)
  const [html, setHtml] = useState('')
  const [showNext, setShowNext] = useState(false)
  const [footShown, setFootShown] = useState(false)

  const scriptRef = useRef<string[]>([])
  const liRef = useRef(0)
  const typingRef = useRef(false)
  const timerRef = useRef<number | null>(null)
  const findIdxRef = useRef(0)
  // Mirror the ranked list into a ref so the dialogue always resolves the
  // freshest finding even after insights arrive async, without re-running play.
  const rankedRef = useRef<string[]>(ranked)
  useEffect(() => {
    rankedRef.current = ranked
  }, [ranked])
  // Same reason as rankedRef: GetAgentHome resolves a beat after mount, so a
  // script built at mount would tell a scanned site it had only glanced. The
  // ref lets buildScript read the freshest state whenever it runs.
  const homeRef = useRef(home)
  useEffect(() => {
    homeRef.current = home
  }, [home])
  const currentFinding = (): string =>
    rankedRef.current.length ? rankedRef.current[findIdxRef.current % rankedRef.current.length] : GENERIC_FINDINGS[0]
  const resolve = (t: string): string => (t === '%FIND%' ? currentFinding() : t)

  const clearTimer = () => {
    if (timerRef.current) window.clearInterval(timerRef.current)
    timerRef.current = null
  }

  const swapPose = useCallback((p: Pose) => {
    setPoseVisible(false)
    window.setTimeout(() => {
      setPose(p)
      setPoseVisible(true)
    }, 140)
  }, [])

  /**
   * What the guide SAYS it is doing, from what the run actually did.
   *
   * Keyed off the last real tool rather than a timed script: an audit does not
   * run in a fixed order, so a scripted sequence drifts out of step with the
   * real thing within seconds — and then the terminal narrates fiction over
   * live work. Unknown tools fall back to something true but vague rather than
   * inventing an activity.
   */
  const SCAN_NARRATION: Record<string, string> = {
    listEditablePosts: 'Taking stock of your pages and posts',
    getSiteDesign: 'Looking at how the place is put together',
    getSiteStyle: 'Reading your colours and type',
    auditSiteLinks: 'Following every link to see where it actually goes',
    viewSite: 'Standing back and looking at it, desktop and phone',
    checkContrast: 'Checking anyone can actually read this',
    checkCopy: 'Reading the words like a visitor would',
    getSeoGuidance: 'Working out what Google needs from you',
    searchSiteContent: 'Digging through what you have written',
    grepPosts: 'Searching the copy for patterns',
    listForms: 'Checking your forms can actually catch a lead',
    getSiteInsights: 'Going over what visitors have been asking',
    listGuardrails: 'Reading the rules you set me',
    runAnalyticsQuery: 'Pulling the numbers',
    wordpressRead: 'Reading your settings',
    renderDocument: 'Writing all of this up for you',
    // Added after watching a real run fall back to the generic line three
    // times. The fallback is correct behaviour — better vague than invented —
    // but "Going through your site" appearing between two specific lines reads
    // as the narration stalling. Anything the audit routinely calls belongs
    // here; check a live run's ledger before assuming this list is complete.
    getPostSource: 'Reading a page the way it was actually written',
    listTemplates: 'Looking at the layouts you have to work with',
    getChatbotInstructions: 'Checking what your visitor bot has been told to say',
  }
  const narrate = (tool?: string): string =>
    (tool && SCAN_NARRATION[tool]) || 'Going through your site'

  /**
   * How the guide closes. ONE line, every time, and that is deliberate.
   *
   * This was briefly severity-branched — "that one is losing you people TODAY,
   * it would be the first thing I fixed" — which broke in two ways. The drip
   * rotates a DIFFERENT finding each visit, so "the first thing I fixed" was
   * claimed of five different problems in a row; you cannot have five first
   * things. And reacting to the finding replaced the ask with a comment, so the
   * closing beat stopped selling anything.
   *
   * The closer's job is the ASK, not the reaction — the finding above it
   * already carries the specifics. Only "20-minute" was ever wrong here: a
   * duration the guide cannot know, and plainly false against something like
   * "zero SEO metadata on every post". That word is gone; the rest earns its
   * place.
   */
  const CLOSER = "That's a job for an Agent, not a chatbot. Come back when you want to GROW, not just answer questions."

  const buildScript = useCallback(() => {
    // '%FIND%' is resolved to the current best finding at type time (below), so
    // it reflects insights even if they arrived after the script was built.
    //
    // The whole script follows from what has actually happened. Before the free
    // scan the guide has only GLANCED — those findings are heuristics computed
    // from WP data without ever loading the site — so it says so, and offers
    // the real look. Claiming "I ran a quick scan on your site" before running
    // one had the guide lying in its first three seconds: wrong note for the
    // character, and wrong note for us.
    const h = homeRef.current
    const scanned = h?.scanStatus === 'ready' || h?.scanStatus === 'used'
    // THE REVEAL. When the scan has just finished, the guide opens on the NEWS,
    // not on the sales opener it already delivered three minutes ago.
    //
    // It used to replay the full four beats from "Happy with your visitor bot?"
    // — so the payoff for a three-minute wait was the same line the owner
    // started with, and nothing on screen said the scan had finished. Worse,
    // the fetch that triggered the replay STAMPS a finding as shown, so that
    // finding was burned on a beat nobody ever saw.
    if (revealRef.current) {
      scriptRef.current = ['Right. I went through the whole thing.', '%FIND%', CLOSER]
      return
    }
    scriptRef.current = scanned
      ? [
          "Happy with your visitor bot? Then you don't need me. Keep the $49.",
          'But I went through your whole site, page by page.',
          '%FIND%',
          CLOSER,
        ]
      : [
          "Happy with your visitor bot? Then you don't need me. Keep the $49.",
          "Mind you, I have only glanced at your site from the outside.",
          '%FIND%',
          'Let me go through the whole thing properly. Once, free. Then you decide.',
        ]
  }, [])

  const typeLine = useCallback((text: string, done?: () => void) => {
    typingRef.current = true
    setShowNext(false)
    let i = 0
    clearTimer()
    timerRef.current = window.setInterval(() => {
      setHtml(text.slice(0, i) + '<span class="bb-scr-caret"></span>')
      i++
      if (i > text.length) {
        clearTimer()
        typingRef.current = false
        setHtml(bold(text))
        if (done) done()
        else setShowNext(true)
      }
    }, 16)
  }, [])

  const showFoot = useCallback(() => {
    setFootShown(true)
    swapPose('coding')
  }, [swapPose])

  const play = useCallback(() => {
    // The scan owns the terminal while it runs, so there is nothing to type
    // into — and a timer ticking behind a hidden view would land its last
    // character at whatever moment the scan happened to finish. The replay on
    // completion starts it cleanly instead.
    if (homeRef.current?.scanStatus === 'running') return
    // Rebuild first: the state may have resolved since the last play, and a
    // replay that still used the old branch would contradict what the button
    // beneath it now says.
    buildScript()
    liRef.current = 0
    setFootShown(false)
    swapPose('hi')
    typeLine(resolve(scriptRef.current[0]))
  }, [swapPose, typeLine, resolve, buildScript])

  const advance = useCallback(() => {
    if (typingRef.current) {
      clearTimer()
      typingRef.current = false
      setHtml(bold(resolve(scriptRef.current[liRef.current])))
      setShowNext(true)
      return
    }
    const s = scriptRef.current
    if (liRef.current < s.length - 1) {
      liRef.current += 1
      swapPose(POSE_SEQ[Math.min(liRef.current, POSE_SEQ.length - 1)] as Pose)
      typeLine(resolve(s[liRef.current]), liRef.current === s.length - 1 ? showFoot : undefined)
    } else {
      showFoot()
    }
  }, [swapPose, typeLine, showFoot, resolve])

  const rescan = useCallback(
    (e: ReactMouseEvent) => {
      e.stopPropagation()
      findIdxRef.current += 1
      buildScript()
      play()
    },
    [buildScript, play],
  )

  useEffect(() => {
    buildScript()
    play()
    return clearTimer
    // run once on mount
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /**
   * Restart when the guide's story actually changes underneath it.
   *
   * Two moments matter: the state resolving just after mount (the script was
   * built from `undefined`), and the scan FINISHING while the owner watches —
   * that one is the payoff, and a guide still saying "I have only glanced"
   * over a completed scan would be the stale-line problem all over again.
   *
   * Guarded so it cannot interrupt someone mid-read: only replays while still
   * on the opening line, or once the scan lands. Keyed on the status STRING so
   * an unrelated re-render never restarts the dialogue.
   */
  // True only for the play() that immediately follows a scan completing, so the
  // reveal script is used once and every later visit gets the normal pitch.
  const revealRef = useRef(false)
  const lastStatusRef = useRef<string | undefined>(undefined)
  useEffect(() => {
    const status = home?.scanStatus
    if (!status || status === lastStatusRef.current) return
    const previous = lastStatusRef.current
    lastStatusRef.current = status
    const justFinishedScanning = previous === 'running' && (status === 'ready' || status === 'used')
    const stillAtTheStart = liRef.current === 0
    if (justFinishedScanning || (previous === undefined && stillAtTheStart)) {
      revealRef.current = justFinishedScanning
      buildScript()
      play()
      revealRef.current = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [home?.scanStatus])

  return (
    <section className="bb-agent-pitch2">
      <div className="bb-ap-stage">
        <div className="bb-ap-mascot-col">
          <img
            className={`bb-ap-mascot ${poseVisible ? '' : 'bb-ap-hidden'}`}
            src={poseUrl(pose)}
            alt="Youami, your agent"
            draggable={false}
          />
          <span className="bb-ap-name">youami · agent</span>
        </div>

        <div className="bb-ap-right">
          <div
            className="bb-ap-screen"
            onClick={advance}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                advance()
              }
            }}
          >
            <div className="bb-ap-scr-tag">▮ YOUAMI</div>
            {isScanning ? (
              /* LIVE. The terminal narrates the run instead of replaying the
                 pitch — which is also why navigating away and back no longer
                 restarts anything: this is driven by state, not by a one-shot
                 typing animation. */
              <div className="bb-ap-scr-body bb-ap-scanlog">
                <p className="bb-ap-scanlog-now">
                  {narrate(home?.scanProgress?.tool)}
                  <span className="bb-ap-scanlog-dots" aria-hidden>…</span>
                </p>
                <p className="bb-ap-scanlog-meta">
                  {home?.scanProgress?.steps
                    ? `${home.scanProgress.steps} things checked so far. This takes a couple of minutes — you can leave this tab.`
                    : 'Getting started. This takes a couple of minutes — you can leave this tab.'}
                </p>
              </div>
            ) : (
              /* Controlled copy only (our strings + PHP-sanitised findings). */
              <div className="bb-ap-scr-body" dangerouslySetInnerHTML={{ __html: html }} />
            )}
            <div className={`bb-ap-next ${showNext && !isScanning ? '' : 'bb-ap-hidden'}`}>▶</div>
          </div>

          <div className={`bb-ap-foot ${footShown ? 'bb-ap-show' : ''}`}>
            <button
              type="button"
              className="bb-btn bb-btn-primary bb-ap-cta"
              onClick={() => setChangePlanOpen(true)}
            >
              Hire the Agent · $49/mo
            </button>
            {/* THE ONE FREE SCAN. Until it is used, this is the honest offer:
                everything the mascot has shown so far is a cheap heuristic
                computed without looking at the site. Once it runs, the button
                becomes the rotator again — but now over things the agent
                actually observed. */}
            {home?.scanStatus === 'offered' ? (
              <button
                type="button"
                className="bb-ap-rescan bb-ap-scan"
                disabled={startScan.isPending}
                onClick={() => startScan.mutate()}
              >
                {startScan.isPending ? '◌ starting…' : '⌖ scan my whole site — once'}
              </button>
            ) : home?.scanStatus === 'running' ? (
              <span className="bb-ap-rescan bb-ap-scanning" role="status" aria-live="polite">
                ◌ scanning your site…
              </span>
            ) : (
              <button type="button" className="bb-ap-rescan" onClick={rescan}>
                ↺ what else did you spot?
              </button>
            )}
            <span className="bb-ap-note">Cancel anytime. No setup.</span>
          </div>
        </div>
      </div>
      <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
    </section>
  )
}
