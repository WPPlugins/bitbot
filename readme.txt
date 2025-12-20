=== BitBot ===
Contributors: youamibot
Donate link: https://youami.ai
Tags: chatbot, ai chatbot, content generator, ai content, multilingual, bot, live chat, ai assistant, seo content, blog generator
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered multilingual chatbot that helps visitors navigate your website in any language, plus AI content generation.

== Description ==

BitBot by youami.ai transforms your WordPress site with an intelligent AI assistant that knows your content and speaks your visitors' language.

= Multilingual Chat Assistant =

* **Supports 100+ languages** - Visitors can ask questions in their native language
* **Automatic language detection** - No configuration needed, just works
* **Cross-language search** - Finds relevant content regardless of query language
* **Responds in visitor's language** - Natural conversation in any language
* Answers visitor questions based on your actual site content
* Provides clickable links to relevant pages and sections
* Gracefully handles questions it cannot answer
* Customizable appearance and widget position
* Mobile-responsive chat widget

**Reach a global audience** - Whether your site is in English, Spanish, Hindi, Chinese, Arabic, or any other language, BitBot helps visitors find what they need in the language they prefer.

= AI Content Generator =

* Creates SEO-optimized blog posts based on your site's niche
* Discovers content gaps and suggests topics
* Aware of your existing content to avoid duplication
* Full editorial control - review and approve before publishing
* Built-in SEO metadata generation

= Key Features =

* **Global language support** - Works with any WordPress site language
* Automatic sitemap crawling and content indexing
* Deep linking to page sections via anchor tags
* Conversation history for context-aware responses
* Usage tracking dashboard
* Subscription management with Stripe

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
3. Go to BitBot in the admin menu to view the dashboard
4. Your site will automatically register and begin syncing content
5. Configure chatbot appearance in BitBot > Settings

== Frequently Asked Questions ==

= Does BitBot support my language? =

Yes! BitBot supports 100+ languages out of the box. It automatically detects the visitor's language and responds accordingly. Your site can be in any language - English, Spanish, French, German, Hindi, Chinese, Arabic, Japanese, Korean, Portuguese, Russian, and many more.

= How does the chatbot learn about my site? =

On activation, the plugin fetches your sitemap and crawls all public pages. Content is processed and indexed for AI retrieval. You can manually trigger a sync anytime from the dashboard.

= Does BitBot store my content? =

Yes, your page content is stored securely on youami.ai servers to enable the AI chat functionality. This data is only used to answer visitor questions and is not shared with third parties or used to train AI models.

= What happens to visitor chat data? =

Chat conversations are stored to provide context-aware responses and allow you to review interactions in the dashboard. Visitor IP addresses may be collected for analytics purposes. You can view all conversations in your WordPress admin dashboard.

= How is the content generation different from other AI tools? =

Our content generator is aware of your existing site content. It creates complementary posts that don't duplicate what you already have, ensuring fresh, unique content that fills gaps in your coverage.

= What AI model powers this? =

We use Claude by Anthropic for both chat responses and content generation, known for high-quality, nuanced outputs.

= Is there a free trial? =

Yes! Your first month is completely free. After that, the service is $10/month. You can cancel anytime.

= What data is sent to external servers? =

* Website URL and sitemap location
* Public page content for indexing
* Chat messages between visitors and the bot
* Visitor IP addresses for conversation tracking
* Email address for subscription billing (via Stripe)

= Can I delete my data? =

Yes. Contact support@youami.ai to request complete deletion of your site data from our servers.

= Does it work with caching plugins? =

Yes, BitBot works with all major caching plugins. The chat widget loads asynchronously and doesn't affect page caching.

= Does it work with any theme? =

Yes, BitBot is compatible with any modern WordPress theme and page builder (Elementor, Divi, Gutenberg, etc).

== Screenshots ==

1. AI Chatbot in action - Visitors ask questions and get instant answers with citations
2. Content gap analysis - Discover missing topics and generate SEO-optimized posts
3. Generated blog post - AI writes complete articles ready for review
4. Multilingual support - Chatbot responds in visitor's language (Spanish, Arabic, etc.)
5. Admin dashboard - Monitor usage, sync status, and manage settings
6. Content sync - Click "Sync Now" to index your pages and make them searchable by the chatbot

== Changelog ==

= 1.3.0 =
* **URL-based content generation** - Generate blog posts from any web URL
* Simplified source type dropdown - removed redundant options
* Improved error handling with user-friendly messages
* Bug fixes and performance improvements

= 1.2.0 =
* Page management - add, remove, toggle and resync individual pages
* Conversation history viewer in admin dashboard
* Reconnect functionality for API key refresh
* Improved sync reliability

= 1.1.0 =
* **Multilingual support** - 100+ languages with automatic detection
* Cross-language search - query in any language, find content in site's language
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

This data is processed on youami.ai servers and may be transmitted to third-party AI services (Anthropic, OpenAI) for processing. Your content is never used to train AI models. We take your privacy seriously.
