=== Youami – AI Chat, Voice Calls, Forms & Leads ===
Contributors: youamibot
Donate link: https://youami.ai
Tags: chatbot, ai, voice, forms, lead generation
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitors ask by text or voice, get answers from your own pages, and leave their details. Free to start, set up in two minutes.

== Description ==

**Your site, answering questions day and night.** Youami reads your public pages and answers visitors from them, with a link to the page it used. When someone wants a person, it collects their name and email, or hands them to your WhatsApp.

No API keys, no separate dashboard. Install, activate, press Connect, and the chat is live. Everything is managed inside WordPress.

= Talk, not just type =

* **Voice calls from the widget.** A visitor taps Call and talks to your site. Pick the voice that suits your brand. Assistant and Agent plans.
* **WhatsApp button.** Add your number and visitors can carry on the conversation with you in WhatsApp, on any plan.
* **Chat in their language.** Offer the languages you serve. Youami replies in the visitor's language and you edit the welcome copy for each one.

= Never lose an enquiry =

* **Ask for details before the first reply.** Turn on the pre-chat form and every chat starts with a name and an email.
* **Forms on your pages.** Build a form in minutes, or let Youami draft one for you, and embed it anywhere with a shortcode.
* **One inbox.** Every lead, with what they asked, lands in Youami inside WordPress.
* **Follow up faster.** Youami researches the lead and drafts the reply for you.

= Keep your content working =

* **Always in sync.** Publish or edit a page and Youami learns it.
* **See what visitors ask that you have not written.** Coverage gaps and topic ideas, straight from real questions.
* **Draft posts from a topic, a link or a document.** Review text, images and SEO details, then publish.

= Agent: let it run the site =

* **Create and edit.** New pages and homepages from your own theme, and changes to any post or page, by asking for them.
* **Design and SEO.** The look, the CSS, titles, descriptions, broken links and site audits, with the fixes applied.
* **Run it.** Posts written and published, recurring jobs on a schedule, WooCommerce, Search Console, Zoho and Pipedrive used on your behalf.

= Plans =

**Free.** Text chat that answers from up to 500 of your pages, your newest conversation in full, every lead with their message, two forms, three topic searches, one site scan, and the WhatsApp button.

**Assistant, $20 USD a month.** Everything in Free, plus voice calls, every transcript, form answers, lead research and follow-up drafts, post generation, AI-built forms, lead emails.

**Agent, $49 USD a month.** Everything in Assistant, plus your own website manager: an agent that looks after your site, builds pages or a whole site from scratch, uses your connected business tools and runs the recurring work on a schedule.

== Installation ==

1. Plugins, Add New, search for Youami, install and activate.
2. Open Youami in your WordPress menu and press Connect to youami.ai. This registers your site and sends your published pages there so the chat can answer from them. Nothing is sent before you press it.
3. The chat widget goes live on your site. Watch the sync finish, then ask it something.

== Frequently Asked Questions ==

= Do I need an AI account or API key? =

No. Everything runs through the youami.ai service and is included in your plan.

= Can visitors call my site? =

Yes, on Assistant and Agent. Turn on calls in Youami, pick a voice, and a Call button appears in the widget.

= How does the WhatsApp button work? =

Add your WhatsApp number in Youami and a WhatsApp button appears in the widget. Tapping it opens a chat with you in WhatsApp. No WhatsApp account connection is needed, and it works on every plan.

= What does Free include? =

Text chat, your newest conversation in full, every captured lead with their message, two forms, three topic searches and one site scan. Transcripts, form answers, research and follow-ups are on Assistant.

= Does it speak other languages? =

Yes. Offer the languages you serve and Youami replies in the visitor's language. You can edit the welcome copy for each one.

= Will it work with WooCommerce? =

Yes. Youami answers from your product pages on every plan. Agent adds live product cards, checkout links and verified order answers in the chat.

= Will it slow my site down? =

No. The widget loads after your page, and the thinking happens on our service.

= Can Youami get things wrong? =

Yes, like any AI. It answers from your pages and tells visitors when it does not know. Review generated posts and agent changes before you rely on them.

= Where is my data? =

Processed by the youami.ai service to provide the features you use. See https://youami.ai/privacy, or email support@youami.ai to delete it.

= Can I cancel? =

Yes, inside WordPress, any time. Your plan stays active until the end of the billing period.

== External services ==

Youami is a connector to the youami.ai service. Nothing is sent to any service until the site administrator presses "Connect to youami.ai" in the Youami admin. Activating the plugin sends nothing.

Sites that registered with an earlier version count as already connected after updating; deactivate the plugin to stop sending.

The assistant can work in your wp-admin with your permission, or without asking each time if you turn on Auto mode.

= youami.ai (the Youami service) =

What it is: the hosted service that answers visitors, stores leads, builds drafts and runs the Agent. Address: https://www.youami.ai/api (the admin screens also call it from the administrator's browser).

What it is used for: every feature of the plugin.

Data sent and when: when the administrator presses Connect, and then whenever a page is published or edited, or the administrator presses Sync: the site address, locale, admin email address, plugin version and the title, text, links and images addresses of published pages and posts. While the widget is in use: each visitor message, the recent conversation, a random visitor and session id, the visitor's IP address (for rate limits and an approximate country), browser language and time zone, and the details a visitor types into a chat form or a Youami form (name, email, phone, message). Admin actions (settings, leads, drafts, billing) send what the administrator enters.

Terms of service: https://youami.ai/terms
Privacy policy: https://youami.ai/privacy

= Providers that youami.ai uses on your behalf =

These are contacted by the youami.ai service, not by the plugin. The text a visitor writes and the page content needed to answer are passed to them as needed:

* Anthropic (AI replies and drafts): https://www.anthropic.com/legal/commercial-terms and https://www.anthropic.com/legal/privacy
* Cohere (search embeddings of your page text): https://cohere.com/terms-of-use and https://cohere.com/privacy
* Tavily (web research for drafts, Agent plan): https://tavily.com/terms and https://tavily.com/privacy
* Unsplash (stock images; a search phrase only): https://unsplash.com/terms and https://unsplash.com/privacy
* Resend and Forward Email (sending lead and notification email): https://resend.com/legal/terms-of-service, https://resend.com/legal/privacy-policy, https://forwardemail.net/en/terms and https://forwardemail.net/en/privacy

= Supabase Storage (file upload for post drafts) =

What it is: file storage used by youami.ai. Address: the signed upload address that youami.ai returns, on supabase.co.

What it is used for: when the administrator chooses "Generate a post from a document", the plugin uploads that one file straight to storage so the service can read it.

Data sent and when: the file the administrator picked, only at that moment, to the temporary signed address.

Terms of service: https://supabase.com/terms
Privacy policy: https://supabase.com/privacy

= Stripe (billing, paid plans only) =

What it is: payment processor. Addresses: https://checkout.stripe.com and https://billing.stripe.com.

What it is used for: when the administrator chooses a paid plan or opens "Manage card and invoices", the browser is sent to a Stripe page created by youami.ai.

Data sent and when: only at that moment, in the browser, directly to Stripe: payment details the administrator types there. The plugin never sees card details.

Terms of service: https://stripe.com/legal/ssa
Privacy policy: https://stripe.com/privacy

= Habitat voice calls (only if voice calls are switched on) =

What it is: the voice service behind the Call button (platform.the-habitat.ai, with LiveKit for audio). Voice calls are off by default.

What it is used for: a visitor taps Call and talks to the site.

Data sent and when: when a visitor starts a call, the visitor's browser loads the voice script from the address youami.ai returns for that call and streams the visitor's microphone audio to the voice service for the length of the call. The administrator controls whether the Call button exists.

Terms of service: https://the-habitat.ai/terms
Privacy policy: https://the-habitat.ai/privacy
LiveKit: https://livekit.io/legal/terms-of-service and https://livekit.io/legal/privacy-policy

= WhatsApp (only if the WhatsApp button is switched on) =

What it is: a link to https://wa.me that opens WhatsApp. Nothing is sent by the plugin; the visitor leaves for WhatsApp when they click.

Terms of service: https://www.whatsapp.com/legal/terms-of-service
Privacy policy: https://www.whatsapp.com/legal/privacy-policy

= PostHog (anonymous usage analytics, off by default) =

What it is: product analytics. Address: https://us.i.posthog.com (or the host set in Youami).

What it is used for: understanding how the Youami admin screens are used and fixing errors in them. It runs only in wp-admin, never on visitor pages.

Data sent and when: only after the administrator switches on "Share anonymous usage analytics" (Youami admin, account panel). Then: pages and buttons used in the Youami admin, error reports, session recordings of the Youami admin, the site domain, plan and plugin version. Until it is switched on, no PostHog script is loaded and no event is sent.

Terms of service: https://posthog.com/terms
Privacy policy: https://posthog.com/privacy

= Optional public link =

The "Powered by" link under the chat, and the "Draft by" line at the end of generated posts, appear only if the administrator turns on "Show Powered by link" in the Youami admin. They are off by default.

== Source code and build ==

The admin screens (assets/admin/*.js and admin.css) are built from the React and TypeScript source that ships inside this plugin, in the admin-react folder (admin-react/src, admin-react/package.json, admin-react/vite.config.ts, admin-react/tsconfig.json, admin-react/index.html). The front-end widget (assets/js/ and assets/css/) is plain, unminified JavaScript and CSS.

To rebuild the admin bundle you need Node.js 20 or newer:

1. cd admin-react
2. npm ci
3. npm run build

The build writes the files to assets/admin. The npm run build command runs tsc and then vite build.

== Screenshots ==

1. Your chat answers right away, with a Call button for voice and a WhatsApp button to continue with you.
2. Ask for a name and email before the first reply, so every chat starts with a lead.
3. Every conversation in one place, with a flag next to each visitor and the transcript beside it.
4. Open a lead and Youami researches who they are and suggests the next step.
5. Visitors browse product cards from your store without leaving the chat.
6. What visitors ask, which pages answered, and what your site still needs to cover.
7. Turn the pre-chat form on in one switch and see exactly what visitors will see.
8. Offer the chat in the languages you serve, with the welcome copy for each.
9. Build a form in minutes and embed it anywhere with a shortcode.

== Changelog ==

= 2.2.9 =

* Free plan now reads up to 500 pages, up from 100.
* On sites with more pages than the plan allows, the assistant now reads your most important pages first: the home page, the pages in your menus, then your main pages before their sub-pages.
* Find Topics keeps your latest results when you leave the page and come back.
* Picking a topic fills in writing instructions specific to your site.
* Pages too short to answer from are no longer shown as left out for the page limit.
* New Chatbot setting: Press Enter to send. The message box now takes several lines. Press Enter to send is off by default, so Enter starts a new line and visitors click the send button. Turn it on under Chatbot, Visibility & prompts, to send with Enter again.
* The pre-chat form's heading, buttons and error messages now follow the visitor's language.
* Web addresses in replies are shown in readable form, and links to your own site open in the same tab.

= 2.2.7 =

* Knowledgebase is now one place with tabs for indexed pages, FAQs, topics, posts and drafts.
* Free plan: up to 100 pages in the knowledge base, 3 topic searches a month and 1 generated post a month. Sites already above 100 pages keep them.
* You choose which pages the assistant reads, and can swap one page for another when the list is full.
* Sync shows what it is doing while it runs.
* Drafts open on the newest draft and show the body of posts that already live in WordPress.
* Generated posts carry no credit line; the only attribution is the opt-in link under the chat widget.
* The assistant's Additional CSS edits are paused in this release; your existing CSS is preserved.
* The assistant uses a dedicated account created by the plugin; it never takes over an existing WordPress account.

= 2.2.6 =

* The plugin is now called Youami.
* Nothing is sent to youami.ai until you press Connect. Sites already connected keep working.
* "Powered by" and admin usage analytics are off until you turn them on.
* The assistant no longer installs or switches plugins and themes, and its helper user has content permissions only.
* Product cards show under chat answers that name your WooCommerce products, with the sale price beside the regular price.
* The chat greets visitors with your current site title.
* Coverage gaps now fill in on the Agent plan.

= 2.2.5 =

* Sync content paces itself to your host: it slows down only when your visitors would feel it, and finishes in minutes on busy shared hosting.
* Chat answers follow-up questions on large sites faster.
* Machine-translated widget text refreshes without overwriting your own edits.

= 2.2.4 =

* Voice calls answer sooner, and a short thinking sound fills any pause instead of silence.
* The voice welcome speaks in the chosen voice's own gender in every language.
* Sync content reads your pages from inside WordPress, shows each stage of the sync, and can be stopped.
* Chat finds the right page on large sites through page summaries.
* Conversation transcripts show formatting instead of raw markdown.
* Voice languages save exactly as the picker shows them.

= 2.2.3 =

* The settings page loads on hosts where the WordPress REST API is reached through ?rest_route= instead of /wp-json/.
* The chat widget opens in your site's language, with its title, welcome and input hint built in for 35 languages, and lays itself out right-to-left for Arabic, Persian, Hebrew and Urdu.
* Adding a widget language works on the Free plan.

= 2.2.2 =

* Voice calls connect faster: the widget starts the call directly, and a call that was started but never joined no longer blocks the line.
* A quiet call shows a countdown from 30 seconds and ends at 60, and ends on its own after a goodbye or a "thank you".

= 2.2.1 =

* Invitation bubble: shows after the delay you set, slides in beside the chat button and gives it a short buzz. Pill style, no close button, fades out on its own.
* WhatsApp: the opening message shows your site name correctly.
* Agent: numbered lists render properly, replies format as they stream, and a reply no longer disappears while the agent is working.

= 2.2.0 =

* Voice calls on paid plans. Pick a voice and visitors can call your site from the widget.
* WhatsApp button in the widget.
* Pre-chat form: ask for a name and email before the first reply, with a live preview in the settings.
* Every lead and your latest conversation on Free. Transcripts and follow-up tools on Assistant.
* Country flags on conversations, one cleaner conversation view, and badges only when a thread needs you.
* Forms: every submission is kept and emailed, repeat enquiries are never lost, closed forms stay closed.
* Agent: site editing, scheduled work, site audits, support inbox, usage credits and top-ups.
* Many fixes and speed improvements.

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

Major release - Youami grows from a chatbot into a full AI assistant for your site.

* New: AI Agent (Pro+) - the chatbot uses your connected tools to act, not just answer.
* New: Integrations - connect WooCommerce, Zoho CRM, Pipedrive, and Google Search Console.
* New: Insights - Top Questions, Coverage Gaps, and high-intent visitors who didn't leave an email, on the Overview tab.
* New: Leads inbox upgrades - one-click CRM sync with live pipeline status, AI lead research, AI-drafted follow-up emails, and bulk select / mark-read / delete.
* Improved: Multi-language answers across 100+ languages, with sharper context understanding on Pro+.
* Improved: Rebuilt admin dashboard - faster and cleaner across every tab.

= 1.8.3 =
*Release Date - 14 May 2026*

* Fix: Content indexing edge case handled - more of your site's pages now make it into the chatbot index.
* Fix: Mobile view improved.

= 1.8.2 =
*Release Date - 12 May 2026*

* Fix: WooCommerce products and other custom post types now sync into the chatbot index.
* Fix: Chat widget mounts inside a Shadow DOM so theme button/SVG styles no longer distort it.

= 1.8.1 =
*Release Date - 8 May 2026*

* Add: Inline post-cap notice - Free-tier post limit now surfaces as a yellow WordPress admin notice with an inline Upgrade button, replacing the previous browser alert dialog.
* Add: Lead-capture honesty - when a Free-tier site is at the 10-lead cap, the chatbot now tells the visitor "we're at capacity, please email us directly" instead of falsely confirming capture.
* Add: Reactivate Subscription button on the dashboard for cancelled plans inside the billing period.
* Fix: Widget now surfaces the backend's specific error message (cap reached, plan required, etc.) instead of a generic "I encountered an error" toast.
* Fix: Leads / Drafts / Conversations admin pages were silently capped at 1,000 rows - now properly paginated with Prev / Next controls. Older records are no longer hidden.
* Fix: Pro → Pro+ subscription swap occasionally reverted to Pro because the Stripe webhook read tier from lagging metadata. Now resolves tier from the active price ID - swap completes immediately.
* Fix: Product-card rendering - `searchProducts` honours the `onSale`, `minPrice`, `maxPrice`, and `orderBy` filters that were previously declared but ignored. "What's on sale?" now returns every on-sale product, not a text-match clip.
* Fix: Multilingual chat no longer appends an English meta-narration after a localised reply. Hindi visitor → Hindi reply, full stop.
* Fix: Agent re-fetches product results on every product-asking turn, even if it called the same tool last turn - cards stay attached to the message that triggered them, not orphaned above on a previous turn.
* Fix: OTP retry behaviour - typing the wrong code no longer auto-resends a fresh OTP. The original code stays valid; the visitor just retypes.
* Fix: Filtered Leads inbox no longer shows "No leads yet" when the filter excludes everything - now says "No leads match this filter" with a clear-filters link.
* Fix: Admin assets (`admin.js`, `admin.css`) now include a filemtime cache-bust so in-place plugin updates reach the admin's browser without manual hard-reload.
* Update: `searchProducts` payload is slimmed via WC `_fields` - page-load on a 7-product sale sweep dropped from ~100 KB to ~3.5 KB on the wire.

= 1.8.0 =
* Add: Widget collision auto-stack - detects WhatsApp, accessibility menus and other floating buttons, lifts above them so the chat icon is always tappable.
* Add: Live Chats inbox now uses a 4-step display label (lead name → verified email → "Visitor from City, Country" → anonymous handle) instead of raw IPv6 addresses.
* Update: Multi-tool turns now run in parallel - chat responses for product browse + RAG fan out together instead of serially.
* Update: WooCommerce skill teaches the agent to tree-walk product categories when a plural noun ("hoodies") doesn't text-match a single SKU.

= 1.7.0 =
* Add: Pro+ tier ($49/mo) - the chatbot becomes an agent. Skills: product recommendations, draft orders, order enquiries, and live handover.
* Add: Live Chats admin dashboard with PRESENT / IDLE / LEFT presence pills and a Take Over button.
* Add: Visual product cards in chat (image, price, sale strikethrough, View → CTA).
* Add: Cross-turn memory - tool results persist across turns; agent never asks "which product did you mean?" twice.
* Update: Activation defers outbound API calls to background cron so slow hosts can never brick mid-activation.
* Update: First-sync safety net - hourly retry until content lands.
* Add: Fair-use bars on the Pro / Pro+ Dashboard for chat / posts / leads.

= 1.6.2 =
* Fix: "Powered by Youami" widget footer and "Draft by Youami" post footer are now dofollow links (were nofollow by mistake in 1.6.0–1.6.1).

= 1.6.1 =
* Fix: Dashboard subscription card showed "$10/month" - corrected to Free / Pro at $20/mo.
* Fix: Removed stale "first month free trial" wording.

= 1.6.0 =
* Update: Free plan is now free forever - no expiring trial.
* Add: Free includes 100 chat messages/month, 3 AI posts/month, 1 form, 10 stored leads, multilingual chatbot, full content indexing.
* Add: Pro at $20/mo unlocks unlimited chat / posts / forms / leads, AI Lead Research, drafted follow-up emails, email notifications, Topic Discovery, Style Analysis, AI form generation, removes branding.
* Add: Usage banner on the Dashboard.
* Fix: "Settings saved" notice now appears.
* Fix: Chat no longer resets on cross-page navigation in the same tab.

= 1.5.2 =
* Add: Lead notification emails - get pinged the moment a visitor turns into a lead.
* Add: Notification email setting in Youami → Settings.
* Add: Menu badge for unviewed leads (drops as you open each lead).
* Fix: 500 error when saving posts in the block editor - REST save no longer blocks on background sync.

= 1.5.0 =
* Add: Chat-to-Lead capture - chatbot detects emails in conversation and creates leads automatically.
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
* Add: URL-based content generation - generate posts from any web URL.

= 1.2.0 =
* Add: Page management - add, remove, toggle, resync individual pages.
* Add: Conversation history viewer in admin.
* Add: Reconnect functionality for API key refresh.

= 1.1.0 =
* Add: Multilingual support - 100+ languages with automatic detection.

= 1.0.0 =
* Initial release: chat widget, content sync from sitemap, AI content generator with SEO optimisation, topic discovery, usage tracking, Stripe billing.

== Upgrade Notice ==

= 2.2.9 =
Free plan now reads up to 500 pages and picks your most important pages first. Find Topics keeps your results.

= 2.2.7 =
New Knowledgebase layout, Free plan topic searches and one generated post a month, and fixes from the WordPress.org review.

= 2.2.6 =
Now called Youami. Nothing is sent before you press Connect, and attribution and analytics are opt-in.

= 2.2.5 =
Sync paces itself to your host so visitors never feel it; faster follow-up answers on large sites.

= 2.2.4 =
Faster voice answers, sync from inside WordPress with progress and Stop, better page finding on large sites.

= 2.2.3 =
Settings page fix for some hosts, widget in your site's language, language adding on Free.

= 2.2.2 =
Faster voice call connection and cleaner call endings.

= 2.2.1 =
Invitation bubble and WhatsApp fixes, smoother Agent replies.

= 2.2.0 =
Voice calls, improved Agent tools and widget updates, plus reliability fixes.

= 2.0.2 =
Performance improvements for large page libraries, plus minor fixes.

= 2.0.1 =
Minor UI polish and small fixes.

= 2.0.0 =
Major release. Youami becomes a full AI assistant: the Pro+ Agent, integrations (WooCommerce, Zoho, Pipedrive, Search Console), a redesigned admin, and freemium tiers. Recommended for all users.

= 1.8.3 =
Content indexing edge case handled and mobile view improved. Recommended for all users.

= 1.8.1 =
Big stability + UX pass. Cap messages now surface inline; the chatbot no longer falsely confirms a lead capture when at the Free cap; multilingual replies stay in one language; product-card filters (on sale, price range) work correctly; the Pro→Pro+ swap webhook race is fixed; admin pages past 1,000 rows paginate properly. Recommended for all users.

= 1.7.0 =
Pro+ tier launches - the chatbot becomes an agent that recommends products, takes draft orders with checkout links, looks up orders, and hands off to humans. Live Chats admin dashboard with real-time presence and one-click takeover.

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
