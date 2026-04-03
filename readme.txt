=== BitBot - AI Chatbot & Content Generator ===
Contributors: youamibot
Donate link: https://youami.ai
Tags: chatbot, ai chatbot, multilingual chatbot, customer support, content generator
Requires at least: 5.8
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot that actually knows your website. Answers visitor questions in 100+ languages with citations. Plus AI content generation.

== Description ==

Your visitors have questions. Your FAQ page isn't cutting it. Live chat means hiring staff. Generic chatbots give generic answers.

**BitBot is different.** It reads your entire website, understands your content, and answers visitor questions accurately — with links back to the right pages. And it does it in over 100 languages, automatically.

[See it live on PolishingRocks.com](https://polishingrocks.com) — ask the chatbot anything about rock tumbling.

= What makes BitBot different from Tidio, Crisp, or LiveChat? =

Most chat plugins are either **live chat tools** (someone has to be online) or **rule-based bots** (you write every answer manually). BitBot is neither:

* **No manual setup** — BitBot crawls your sitemap and learns your content automatically
* **No canned responses** — It generates real answers from your actual pages, with citations
* **No language configuration** — Visitors ask in Arabic, Hindi, or Spanish and get answers in the same language
* **No staff required** — It works 24/7 without anyone monitoring it
* **Content generation built in** — Most chat plugins don't generate blog posts for you

= AI Chatbot — Knows Your Content =

* Crawls and indexes every public page on your site
* Answers questions based on your actual content, not generic AI responses
* Provides clickable citations linking visitors to the right pages and sections
* Supports **100+ languages** with automatic detection — no configuration needed
* Remembers conversation context for natural follow-up questions
* Mobile-responsive widget that works on any theme
* Customizable colors and position

= AI Content Generator — Fills Your Content Gaps =

* Discovers trending topics in your niche with one click
* Generates SEO-optimized blog posts aware of your existing content
* Create posts from a prompt, a URL, or an uploaded document
* Full editorial control — preview, edit, and approve before publishing
* Built-in SEO metadata (title, description, focus keywords)
* 7 drafts generated? Review them all before pushing to WordPress

= Built for WordPress =

* One-click install, automatic content sync
* Admin dashboard with usage stats, conversation history, and page management
* Works with all major caching plugins, themes, and page builders (Elementor, Divi, Gutenberg)
* Lightweight — chat widget loads asynchronously, zero impact on page speed

= Pricing =

* **First month free** — full access to chatbot and content generation
* **$10/month after that** — cancel anytime, no contracts

= External Services =

**IMPORTANT: This plugin connects to external services operated by youami.ai**

This plugin requires an active internet connection and communicates with the following external services:

1. **youami.ai API** (https://youami.ai/api)
   * Purpose: Process chat messages, generate content, sync website data
   * Data transmitted: Website URL, sitemap, page content, chat messages, visitor IP addresses (for conversation tracking)
   * When: On plugin activation, content sync, chat messages, content generation
   * [Terms of Service](https://youami.ai/terms)
   * [Privacy Policy](https://youami.ai/privacy)

2. **Claude AI by Anthropic** (via youami.ai servers)
   * Purpose: Generate chat responses and blog post content
   * Data transmitted: Your website content and user queries (processed through youami.ai)
   * Your content is NOT used to train AI models

3. **OpenAI Embeddings API** (via youami.ai servers)
   * Purpose: Create semantic search embeddings for content retrieval
   * Data transmitted: Website page content (processed through youami.ai)

4. **Stripe** (https://stripe.com)
   * Purpose: Subscription payment processing
   * Data transmitted: Email address for billing
   * When: During subscription checkout
   * [Stripe Privacy Policy](https://stripe.com/privacy)

By using this plugin, you agree to the terms of service and privacy policies of these external services.

== Installation ==

1. Upload the `bitbot` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to BitBot in the admin menu — your site automatically registers and starts syncing
4. Wait for content sync to complete (usually under 2 minutes)
5. The chat widget appears on your site immediately — that's it

== Frequently Asked Questions ==

= Does BitBot support my language? =

Yes. BitBot supports 100+ languages out of the box. It automatically detects the visitor's language and responds in that language. Your site can be in English, Spanish, French, German, Hindi, Chinese, Arabic, Japanese, Korean, Portuguese, Russian, or any other language.

= How does the chatbot learn about my site? =

On activation, the plugin fetches your sitemap and crawls all public pages. Content is processed, chunked, and indexed with vector embeddings for semantic search. You can manually trigger a re-sync anytime from the dashboard.

= How is this different from ChatGPT or other AI chatbots? =

Generic AI chatbots don't know your website content. BitBot indexes your specific pages and only answers based on what's actually on your site, with citations linking back to the source. It won't make things up or give generic answers.

= Does BitBot store my content? =

Yes, your page content is stored securely on youami.ai servers to enable AI chat. This data is only used to answer visitor questions and is not shared with third parties or used to train AI models.

= What happens to visitor chat data? =

Chat conversations are stored to provide context-aware responses. You can review all interactions in the admin dashboard. Visitor IP addresses may be collected for analytics.

= How is the content generation different from other AI tools? =

BitBot's content generator knows your existing content. It discovers gaps in your coverage and creates complementary posts that don't duplicate what you already have. It also generates SEO metadata automatically.

= What AI model powers this? =

Claude by Anthropic — known for high-quality, accurate, and nuanced outputs. Used for both chat responses and content generation.

= Is there a free trial? =

Yes. Your first month is completely free with full access. After that, the service is $10/month. Cancel anytime.

= What data is sent to external servers? =

* Website URL and sitemap location
* Public page content for indexing
* Chat messages between visitors and the bot
* Visitor IP addresses for conversation tracking
* Email address for subscription billing (via Stripe)

= Can I delete my data? =

Yes. Contact support@youami.ai to request complete deletion of your site data from our servers.

= Does it work with caching plugins? =

Yes. The chat widget loads asynchronously and doesn't interfere with page caching. Compatible with WP Super Cache, W3 Total Cache, LiteSpeed Cache, and others.

= Does it work with any theme? =

Yes. BitBot works with any modern WordPress theme and page builder including Elementor, Divi, Gutenberg, Astra, GeneratePress, and more.

== Screenshots ==

1. AI Chatbot answering a visitor question with detailed response and clickable citations to source pages
2. Content Generator with topic discovery, prompt-based generation, and a table of generated drafts ready for review
3. Admin Dashboard showing content sync status, usage statistics, subscription info, and indexed pages
4. Multilingual support — chatbot automatically responds in Arabic when a visitor asks in Arabic
5. Mobile-responsive chat widget that works perfectly on phones and tablets
6. Leads Pipeline with stage tracking, AI-suggested next steps, location detection, and source tracking from forms and chat
7. Lead Detail with AI Research summary, Draft Email button, activity timeline with notes and stage changes
8. Form Builder showing all created forms with shortcodes, field counts, and status
9. Create New Form with AI-powered field generation or start with basic fields

== Changelog ==

= 1.4.0 =
* **Leads Pipeline** — capture and manage leads with stage tracking (New, Contacted, Qualified, Won, Lost)
* **Form Builder** — create unlimited lead capture forms, with AI-generated fields or start from basics
* **AI Lead Research** — one-click research on any lead to understand who they are and what they need
* **Draft Email** — AI writes personalized follow-up emails you can copy and send
* **Geolocation** — see where your leads are coming from (country/city)
* **Shortcode embed** — drop `[bitbot_form]` on any page to start capturing leads
* Spam protection with rate limiting on form submissions
* Activity timeline with notes, stage changes, and research history

= 1.3.1 =
* Fixed null page title handling in chat contact page lookup
* Fixed API endpoint configuration

= 1.3.0 =
* **URL-based content generation** — Generate blog posts from any web URL
* Simplified source type dropdown — removed redundant options
* Improved error handling with user-friendly messages
* Bug fixes and performance improvements

= 1.2.0 =
* Page management — add, remove, toggle and resync individual pages
* Conversation history viewer in admin dashboard
* Reconnect functionality for API key refresh
* Improved sync reliability

= 1.1.0 =
* **Multilingual support** — 100+ languages with automatic detection
* Cross-language search — query in any language, find content in site's language
* Responds in visitor's language automatically

= 1.0.0 =
* Initial release
* Chat widget with customizable appearance
* Content sync from sitemap
* AI content generator with SEO optimization
* Topic discovery and content gap analysis
* Usage tracking dashboard
* Subscription management with Stripe integration
* Conversation history viewing

== Upgrade Notice ==

= 1.4.0 =
New leads pipeline! Capture leads with custom forms, track them through stages, and let AI research and draft follow-up emails for you.

= 1.3.1 =
Bug fix for sites with pages that have no title set. Recommended update for all users.

= 1.3.0 =
Generate blog posts directly from any URL! Simply paste a link and BitBot extracts the content to create SEO-optimized posts.

= 1.1.0 =
Major update! BitBot now supports 100+ languages. Visitors can ask questions in their native language and get answers in the same language.

= 1.0.0 =
Initial release of BitBot - AI Chatbot & Content Generator.

== Privacy Policy ==

BitBot collects and processes data as described in the External Services section. For complete details, please review our [Privacy Policy](https://youami.ai/privacy).

Data collected:
* Website content (pages, posts) for AI indexing
* Chat messages between visitors and the bot
* Visitor IP addresses for conversation tracking
* Usage statistics (chat count, posts generated)

This data is processed on youami.ai servers and may be transmitted to third-party AI services (Anthropic, OpenAI) for processing. Your content is never used to train AI models.
