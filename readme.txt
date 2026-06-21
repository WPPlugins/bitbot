=== BitBot - AI Chatbot, Content Generator, Forms & Leads ===
Contributors: youamibot
Donate link: https://youami.ai
Tags: chatbot, ai, live chat, contact form, lead generation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free AI chatbot, content generator, forms, and a leads inbox for WordPress. No API keys, no per-token billing. Install and go in two minutes.

== Description ==

**One install, four AI tools.** Your WordPress site writes content, answers visitor questions, captures leads, and on Pro+ becomes a real agent that talks to your store and your CRM. All from one plugin.

BitBot indexes every page and post on your site. Visitors ask questions in 100+ languages and get accurate answers with clickable citations back to the source page. Form submissions and chat emails land in a single leads inbox. The content generator turns a topic, URL, or uploaded document into an SEO-optimised draft you can preview and publish in one click.

No API keys to manage. No per-token billing. The Free tier covers small sites; Pro removes the caps; Pro+ turns the chatbot into a real agent that searches your WooCommerce catalogue, creates draft orders with a one-click checkout link, looks up order status, and connects your CRM.

[See it live on PolishingRocks.com](https://polishingrocks.com) — ask the chatbot anything about rock tumbling.

= AI Chatbot =

* Indexes every page and post automatically — auto-syncs on publish, update, or delete
* Answers from your content with citations linking back to the source page and section
* 100+ languages with automatic detection — visitor asks in Hindi, bot answers in Hindi, even if your site is in English
* Detects emails in conversation and creates a lead with the full chat transcript attached
* Mobile-responsive widget, customisable colours and corner offset
* Loads asynchronously — zero impact on page speed
* Auto-stacks gracefully when other floating widgets (WhatsApp, accessibility menus) are present

= AI Content Generator =

* Discover Topics — surfaces gaps in what you've already published, suggests SEO-shaped angles to fill them
* Generate from a prompt, a URL, or an uploaded PDF / image
* Knows your existing content and avoids duplicates
* SEO metadata generated automatically (title, description, focus keywords)
* Preview and edit before publishing — drafts open in the WordPress block editor

= Form Builder =

* Describe a form in plain English — BitBot generates fields, types, and validation
* Or start with basic fields and customise manually
* Drag-to-reorder fields
* Embed anywhere with `[bitbot_form id="…"]` — works inside Gutenberg, Classic, page builders
* Rate limiting and spam protection built in (per-IP burst guard, per-email cooldown)

= Leads Inbox =

* Every form submission and chat email becomes a lead
* Stage tracking: New → Contacted → Qualified → Won / Lost
* Geolocation — country and city for every lead
* Activity timeline — notes, stage changes, AI research, sent emails
* Unread badge on the BitBot menu drops as you open each lead
* Search and filter by stage, name, or email — paginated cleanly past 1,000 leads

= Pro: Lead Research & Follow-up =

* AI Lead Research — scrapes the lead's domain, searches the web, reads the full chat conversation, returns a who-they-are / what-they-need summary with a suggested next step
* AI-drafted follow-up emails — copy to clipboard, paste into your mail client
* Email notifications — get pinged the moment a visitor turns into a lead
* Removes "Powered by BitBot" footer link from the widget
* Topic Discovery and Style Analysis on the Content Generator
* AI form-field generation in the Form Builder

= Pro+: The Agent =

Pro+ upgrades the chatbot from a Q&A assistant into a real agent. Skills are modular capabilities the agent activates when a visitor's intent matches — running on real tools, not scripts:

* **Product recommendations** — searches your WooCommerce catalogue live and renders visual product cards in chat (image, price, sale strikethrough, stock)
* **Add to cart / draft order** — agent confirms the exact item and quantity with the visitor, OTP-verifies their email, creates a real pending WooCommerce order, hands them a one-click checkout link
* **Order enquiries** — OTP-verifies the visitor owns the order, then looks up status, tracking, delivery ETA. Never exposes another customer's order
* **Live handover** — if you're online (heartbeat seen in the last 5 minutes), the chat flips to you directly in the Live Chats dashboard. If not, a handover email goes out and a lead is created

The Live Chats dashboard shows every active conversation in real time with PRESENT / IDLE / LEFT presence pills, so you can jump into any thread the moment you want to take over.

= Pricing =

**Free** — 100 chat messages/month, 3 AI blog posts/month (24h cooldown), 1 contact form, up to 10 stored leads, multilingual chatbot, full content indexing. Free forever, no credit card.

**Pro — $20/month** — Unlimited chat, unlimited posts (prompt / URL / PDF), unlimited forms, unlimited leads, AI Lead Research, drafted follow-up emails, email notifications, Topic Discovery, Style Analysis, AI form generation. Removes BitBot branding. Fair-use applies. Cancel anytime.

**Pro+ — $49/month** — Everything in Pro, plus the Agent: a chatbot that actually does things, using a growing list of integrations (WooCommerce, Zoho, Pipedrive, Google Search Console, and more) to meet your business where it already runs, without disrupting your flow. Cancel anytime.

== Installation ==

1. Install BitBot from Plugins → Add New, or upload the `bitbot.zip`.
2. Activate the plugin.
3. Open the **BitBot** menu in your WordPress sidebar — your site auto-registers and starts indexing.
4. Wait ~2 minutes for the first content sync to complete.
5. The chat widget appears on your site automatically.

That's it. No API keys, no SaaS dashboard to log into.

== Frequently Asked Questions ==

= Do I need an OpenAI or Anthropic API key? =

No. BitBot includes the AI service — chat, content generation, embeddings, lead research, and the Pro+ agent all run on infrastructure we operate. No API keys to manage, no per-token billing surprises.

= How long does the chatbot take to learn my site? =

The first sync usually completes in under 2 minutes for a typical site (under 200 pages). Larger sites may take a few minutes. Once it's done, BitBot re-indexes automatically when you publish, update, or delete content.

= Will the chatbot make things up? =

No. BitBot answers only from your indexed content and includes a citation linking back to the source page and section for every answer. If it can't find the answer in your content it says so and offers to capture the visitor's email so you can follow up.

= Does it work in languages other than English? =

Yes. The chatbot detects the visitor's language and answers in the same language — including the product cards on Pro+. We've tested English, Hindi, Spanish, Arabic, French, German, Portuguese, Chinese, Japanese, Korean, and Russian; the underlying model handles 100+ more.

= Will it work with WooCommerce? =

Yes. The chatbot indexes your product pages on the Free and Pro tiers. On Pro+, the dedicated WooCommerce skill goes further: live catalogue search, visual product cards in chat, draft-order creation with checkout links, and OTP-verified order enquiries. Configure WooCommerce REST API keys in BitBot → Settings → WooCommerce Integration.

= Does it slow down my site? =

No. The widget loads asynchronously after the page renders. Works with caching plugins, page builders, and CDNs. The widget detects other floating buttons (WhatsApp, accessibility menus) and auto-stacks above them so it never overlaps.

= How does lead capture work? =

Two paths. Drop `[bitbot_form id="…"]` on any page for a traditional form, or let the chatbot capture leads conversationally — when the bot can't fully answer a question or the visitor mentions wanting to be contacted, it asks for an email and creates a lead with the full conversation transcript attached.

= What happens when I hit the Free tier limits? =

The chatbot shows the visitor a friendly cap message in their language ("we're at capacity for new contact requests right now — please email us directly"). The admin sees a banner on the Leads page with an Upgrade button. Nothing breaks; nothing is lost. Existing chats and leads remain readable.

= Can I export my leads? =

Leads are stored in your dashboard. Bulk CSV export is on the roadmap. In the meantime each lead has a stable URL you can bookmark or share with your team.

= How is my data handled? =

Your content is stored on infrastructure operated by youami.ai (US-based, encrypted at rest). It is used only to answer your visitors' questions and is never used to train AI models. Email support@youami.ai to request deletion at any time.

= What's the difference between Pro and Pro+? =

Pro removes the Free-tier caps and unlocks AI Lead Research, drafted follow-up emails, and email notifications. Pro+ adds the **agent**: a chatbot that takes real actions for your visitors using a growing list of integrations (live WooCommerce product search, draft orders with checkout, order lookups, live human handover, and more), meeting your business where it already runs. If you want conversations that do things, not just answer, Pro+ is what you want.

== Screenshots ==

1. Your whole bot at a glance. Top cited pages, the questions visitors actually ask, coverage gaps to fix, where visitors are from, and how many turn into leads.
2. Visitors browse your WooCommerce catalogue inside the chat — image, price, sale strikethrough, stock — without leaving the page.
3. Every form submission and chat email lands in a single inbox. Stage tracking, location, source, and an unread badge on the BitBot menu.
4. One-click AI research on any lead — pulls from the chatbot's full conversation and the open web, returns a who-they-are summary with a suggested next step.
5. Discover Topics surfaces gaps in what you've already published, suggests SEO-shaped angles, and generates the draft for any one of them in a couple of clicks.
6. Turn on the tools you already use and the agent acts inside the conversation. WooCommerce, Zoho, Pipedrive, Search Console and a growing list, with more shipping every sprint.
7. Describe a form in plain English — BitBot generates the fields, gives you a live preview, and a shortcode you can drop on any page.
8. When the chatbot hands off to a human, the visitor sees a clear "Live: you're now chatting with someone from the team" banner. Their next message lands directly in your Live Chats dashboard.
9. The visitor writes in Hindi, the bot replies in Hindi. Tested across English, Hindi, Spanish, Arabic, French, German and more.

== Changelog ==

= 2.1.0 =
*Release Date - 21 June 2026*

* Multilingual chat: your bot now understands and replies to visitors in many more languages, not just your site's main one. Plus knowledge base improvements (filter pages by source, live sync progress) and reliability fixes.

= 2.0.3 =
*Release Date - 18 June 2026*

* Reliability and performance improvements for content sync, plus minor fixes.

= 2.0.2 =
*Release Date - 18 June 2026*

* Performance improvements for large page libraries, plus minor fixes.

= 2.0.1 =
*Release Date - 18 June 2026*

* Minor UI polish and small fixes.

= 2.0.0 =
*Release Date - 17 June 2026*

Major release — BitBot grows from a chatbot into a full AI assistant for your site.

* New: AI Agent (Pro+) — the chatbot uses your connected tools to act, not just answer.
* New: Integrations — connect WooCommerce, Zoho CRM, Pipedrive, and Google Search Console.
* New: Insights — Top Questions, Coverage Gaps, and high-intent visitors who didn't leave an email, on the Overview tab.
* New: Leads inbox upgrades — one-click CRM sync with live pipeline status, AI lead research, AI-drafted follow-up emails, and bulk select / mark-read / delete.
* Improved: Multi-language answers across 100+ languages, with sharper context understanding on Pro+.
* Improved: Rebuilt admin dashboard — faster and cleaner across every tab.

= 1.8.3 =
*Release Date - 14 May 2026*

* Fix: Content indexing edge case handled — more of your site's pages now make it into the chatbot index.
* Fix: Mobile view improved.

= 1.8.2 =
*Release Date - 12 May 2026*

* Fix: WooCommerce products and other custom post types now sync into the chatbot index.
* Fix: Chat widget mounts inside a Shadow DOM so theme button/SVG styles no longer distort it.

= 1.8.1 =
*Release Date - 8 May 2026*

* Add: Inline post-cap notice — Free-tier post limit now surfaces as a yellow WordPress admin notice with an inline Upgrade button, replacing the previous browser alert dialog.
* Add: Lead-capture honesty — when a Free-tier site is at the 10-lead cap, the chatbot now tells the visitor "we're at capacity, please email us directly" instead of falsely confirming capture.
* Add: Reactivate Subscription button on the dashboard for cancelled plans inside the billing period.
* Fix: Widget now surfaces the backend's specific error message (cap reached, plan required, etc.) instead of a generic "I encountered an error" toast.
* Fix: Leads / Drafts / Conversations admin pages were silently capped at 1,000 rows — now properly paginated with Prev / Next controls. Older records are no longer hidden.
* Fix: Pro → Pro+ subscription swap occasionally reverted to Pro because the Stripe webhook read tier from lagging metadata. Now resolves tier from the active price ID — swap completes immediately.
* Fix: Product-card rendering — `searchProducts` honours the `onSale`, `minPrice`, `maxPrice`, and `orderBy` filters that were previously declared but ignored. "What's on sale?" now returns every on-sale product, not a text-match clip.
* Fix: Multilingual chat no longer appends an English meta-narration after a localised reply. Hindi visitor → Hindi reply, full stop.
* Fix: Agent re-fetches product results on every product-asking turn, even if it called the same tool last turn — cards stay attached to the message that triggered them, not orphaned above on a previous turn.
* Fix: OTP retry behaviour — typing the wrong code no longer auto-resends a fresh OTP. The original code stays valid; the visitor just retypes.
* Fix: Filtered Leads inbox no longer shows "No leads yet" when the filter excludes everything — now says "No leads match this filter" with a clear-filters link.
* Fix: Admin assets (`admin.js`, `admin.css`) now include a filemtime cache-bust so in-place plugin updates reach the admin's browser without manual hard-reload.
* Update: `searchProducts` payload is slimmed via WC `_fields` — page-load on a 7-product sale sweep dropped from ~100 KB to ~3.5 KB on the wire.

= 1.8.0 =
* Add: Widget collision auto-stack — detects WhatsApp, accessibility menus and other floating buttons, lifts above them so the chat icon is always tappable.
* Add: Live Chats inbox now uses a 4-step display label (lead name → verified email → "Visitor from City, Country" → anonymous handle) instead of raw IPv6 addresses.
* Update: Multi-tool turns now run in parallel — chat responses for product browse + RAG fan out together instead of serially.
* Update: WooCommerce skill teaches the agent to tree-walk product categories when a plural noun ("hoodies") doesn't text-match a single SKU.

= 1.7.0 =
* Add: Pro+ tier ($49/mo) — the chatbot becomes an agent. Skills: product recommendations, draft orders, order enquiries, and live handover.
* Add: Live Chats admin dashboard with PRESENT / IDLE / LEFT presence pills and a Take Over button.
* Add: Visual product cards in chat (image, price, sale strikethrough, View → CTA).
* Add: Cross-turn memory — tool results persist across turns; agent never asks "which product did you mean?" twice.
* Update: Activation defers outbound API calls to background cron so slow hosts can never brick mid-activation.
* Update: First-sync safety net — hourly retry until content lands.
* Add: Fair-use bars on the Pro / Pro+ Dashboard for chat / posts / leads.

= 1.6.2 =
* Fix: "Powered by BitBot" widget footer and "Draft by BitBot" post footer are now dofollow links (were nofollow by mistake in 1.6.0–1.6.1).

= 1.6.1 =
* Fix: Dashboard subscription card showed "$10/month" — corrected to Free / Pro at $20/mo.
* Fix: Removed stale "first month free trial" wording.

= 1.6.0 =
* Update: Free plan is now free forever — no expiring trial.
* Add: Free includes 100 chat messages/month, 3 AI posts/month, 1 form, 10 stored leads, multilingual chatbot, full content indexing.
* Add: Pro at $20/mo unlocks unlimited chat / posts / forms / leads, AI Lead Research, drafted follow-up emails, email notifications, Topic Discovery, Style Analysis, AI form generation, removes branding.
* Add: Usage banner on the Dashboard.
* Fix: "Settings saved" notice now appears.
* Fix: Chat no longer resets on cross-page navigation in the same tab.

= 1.5.2 =
* Add: Lead notification emails — get pinged the moment a visitor turns into a lead.
* Add: Notification email setting in BitBot → Settings.
* Add: Menu badge for unviewed leads (drops as you open each lead).
* Fix: 500 error when saving posts in the block editor — REST save no longer blocks on background sync.

= 1.5.0 =
* Add: Chat-to-Lead capture — chatbot detects emails in conversation and creates leads automatically.
* Add: View Conversation on lead detail.
* Add: AI Research now includes the full chat transcript.
* Add: Auto-sync on activation; incremental sync on publish / update / delete.
* Add: Sync Now menu item.

= 1.4.0 =
* Add: Leads Pipeline with stage tracking (New, Contacted, Qualified, Won, Lost).
* Add: Form Builder with AI-generated fields or basic mode.
* Add: AI Lead Research and Draft Email.
* Add: Geolocation on every lead.
* Add: `[bitbot_form]` shortcode.

= 1.3.0 =
* Add: URL-based content generation — generate posts from any web URL.

= 1.2.0 =
* Add: Page management — add, remove, toggle, resync individual pages.
* Add: Conversation history viewer in admin.
* Add: Reconnect functionality for API key refresh.

= 1.1.0 =
* Add: Multilingual support — 100+ languages with automatic detection.

= 1.0.0 =
* Initial release: chat widget, content sync from sitemap, AI content generator with SEO optimisation, topic discovery, usage tracking, Stripe billing.

== Upgrade Notice ==

= 2.0.2 =
Performance improvements for large page libraries, plus minor fixes.

= 2.0.1 =
Minor UI polish and small fixes.

= 2.0.0 =
Major release. BitBot becomes a full AI assistant: the Pro+ Agent, integrations (WooCommerce, Zoho, Pipedrive, Search Console), a redesigned admin, and freemium tiers. Recommended for all users.

= 1.8.3 =
Content indexing edge case handled and mobile view improved. Recommended for all users.

= 1.8.1 =
Big stability + UX pass. Cap messages now surface inline; the chatbot no longer falsely confirms a lead capture when at the Free cap; multilingual replies stay in one language; product-card filters (on sale, price range) work correctly; the Pro→Pro+ swap webhook race is fixed; admin pages past 1,000 rows paginate properly. Recommended for all users.

= 1.7.0 =
Pro+ tier launches — the chatbot becomes an agent that recommends products, takes draft orders with checkout links, looks up orders, and hands off to humans. Live Chats admin dashboard with real-time presence and one-click takeover.

= 1.6.0 =
Free plan is now free forever. Pro at $20/mo for unlimited chat, posts, forms, and leads, plus AI Lead Research and drafted follow-up emails.

= 1.5.0 =
Chat-to-lead capture and incremental sync on publish.

= 1.4.0 =
New leads pipeline. Capture leads with custom forms, track them through stages, let AI research and draft follow-up emails.

= 1.1.0 =
100+ languages with automatic detection.

= 1.0.0 =
Initial release.
