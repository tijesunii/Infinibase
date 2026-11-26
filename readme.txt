=== InfiniBase: AI FAQ & Knowledge Base ===
Contributors: nezeer
Tags: faq, ai, knowledge-base, accordion, customer-support
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your product documentation into an interactive FAQ section with built-in AI search that answers visitor questions accurately.

== Description ==

**Static FAQs only answer 4 or 5 questions. What about everything else your visitors want to know?**

**InfiniBase** combines a clean, modern FAQ accordion with an interactive AI search bar right below it. Visitors can browse your top questions at a glance, or type any specific question in their own words and get an instant, accurate answer pulled directly from your product documentation.

It renders as a fast, native FAQ section right inside your page layout, blending naturally into your website without floating chatbot popups getting in the way.

https://www.youtube.com/watch?v=OQTo6zAGByQ

= How It Works =

1. **Connect Your Preferred AI:** Use WordPress's built-in **Settings → Connectors** or paste your own API key from OpenAI, Anthropic Claude, Google Gemini, or DeepSeek.
2. **Upload Your Docs:** Upload your Markdown (`.md`) documentation files and link each file to the live page URL where that guide lives on your site.
3. **Set Your Top 4 FAQs:** Write your 4 main FAQ cards manually, or let AI generate them from your uploaded docs in one click.
4. **Embed Anywhere:** Drop `[infinibase_light]` or `[infinibase_dark]` into any page, post, Gutenberg block, or Elementor section.

= Why Site Owners Choose InfiniBase =

* **Answers Grounded in Your Docs:** InfiniBase only answers using the documentation you upload. It never guesses or invents features you do not have.
* **Automatic "Read More" Links:** Every AI answer includes a direct link to the exact section of your documentation page so visitors can read further.
* **Smart Support Fallback:** If a visitor asks something that is not covered in your docs, InfiniBase politely lets them know and gives them a direct button to your Contact Support page.
* **1-Click FAQ Generator:** Select any uploaded document and generate 4 ready-to-use FAQs in seconds, then edit them anytime.
* **Light & Dark Themes Built In:** Includes ready-to-use Light (`[infinibase_light]`) and Dark (`[infinibase_dark]`) modes designed to look great on any WordPress theme or page builder.
* **Bring Your Own Key (BYOK):** Connect directly to OpenAI, Anthropic Claude, Google Gemini, DeepSeek, or WordPress Core AI Connectors using your own provider account.

= External Services & Privacy Disclosure =

InfiniBase connects to an external AI provider **only** after a site administrator configures one in **InfiniBase → Settings**. When configured, text is sent to your chosen provider only when:
1. An admin uploads a `.md` file in the dashboard (to index search topics).
2. An admin clicks **Generate 4 FAQs with AI** in the dashboard.
3. A visitor submits a question in the FAQ search bar that matches your uploaded documentation.

Depending on which provider you select, requests are sent to one of the following services:
* **WordPress Core AI Connectors:** [Documentation](https://wordpress.org/documentation/article/settings-connectors-screen/)
* **OpenAI** (`api.openai.com`): [Website](https://openai.com/api/) | [Terms](https://openai.com/policies/terms-of-use/) | [Privacy Policy](https://openai.com/policies/privacy-policy/)
* **Anthropic Claude** (`api.anthropic.com`): [Website](https://www.anthropic.com/api) | [Terms](https://www.anthropic.com/legal/commercial-terms) | [Privacy Policy](https://www.anthropic.com/legal/privacy)
* **Google Gemini** (`generativelanguage.googleapis.com`): [Website](https://ai.google.dev/) | [Terms](https://ai.google.dev/gemini-api/terms) | [Privacy Policy](https://policies.google.com/privacy)
* **DeepSeek** (`api.deepseek.com`): [Website](https://platform.deepseek.com/) | [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html) | [Privacy Policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)

Open-source development repository: https://github.com/tijesunii/Infinibase

== Installation ==

1. Install and activate **InfiniBase** from your WordPress dashboard.
2. Go to **InfiniBase → Settings** to connect your AI provider and add your Contact Support URL.
3. Go to **InfiniBase → Knowledge Base** to upload your `.md` documentation files and set up your 4 FAQ cards.
4. Copy `[infinibase_light]` or `[infinibase_dark]` and paste it into any page or post.

== Frequently Asked Questions ==

= Which AI providers can I use? =
You can connect OpenAI, Anthropic Claude, Google Gemini, or DeepSeek using your own API key, or use WordPress's built-in **Settings → Connectors** screen.

= Will the AI make up answers about my product? =
No. InfiniBase strictly restricts answers to the documentation you have uploaded. If a visitor asks a question that is not covered in your docs, it will not guess. Instead, it shows a friendly fallback message with a button to your Contact Support page.

= Can I use this with Elementor, Gutenberg, or other page builders? =
Yes! InfiniBase works anywhere shortcodes are supported, including the WordPress Block Editor (Gutenberg), Elementor, Bricks, Divi, and classic themes.

= What happens if I don't want to write the 4 FAQ cards myself? =
Inside **InfiniBase → Knowledge Base**, you can select any uploaded document and click **Generate 4 FAQs with AI** to automatically create 4 common questions and answers, which you can edit anytime.

== Screenshots ==

1. Light Mode (`[infinibase_light]`): Clean two-column FAQ accordion with instant AI search and deep-link documentation citations.
2. Dark Mode (`[infinibase_dark]`): Built-in dark theme ready for modern WordPress themes, Gutenberg, and Elementor.
3. Knowledge Base (Documents): Upload Markdown documentation, track live Ready / Optimizing status, and copy Light or Dark embed shortcodes in one click.
4. Knowledge Base (FAQ Cards & Display): Customize your section heading and subtitle, generate 4 starter FAQs with AI, and manage predefined FAQ cards.
5. Settings (AI Provider): Choose WordPress Core AI Connectors or Bring Your Own Key (OpenAI, Anthropic, Google Gemini, DeepSeek) with a 1-click Test Connection check.
6. Settings (Behavior & Safety): Pick Brief or Standard answer styles, configure your Contact Support fallback URL, and control data retention on uninstall.
7. Settings (Support & Diagnostics): Copy a safe, key-free system snapshot in one click when requesting help on the WordPress.org support forum.

== Changelog ==

= 1.0.2 =
* New: Refreshed the entire InfiniBase admin experience with a cleaner, calmer layout, organized tabs for both Knowledge Base and Settings, visual Light & Dark shortcode preview cards, and selectable AI Provider tiles.
* New: Added a 1-click "Test Connection" button on the Settings page that confirms your setup or shows clear error messages right below your API key.
* New: Added a dedicated Support & Diagnostics tab with direct links to the WordPress.org support forum, setup video tutorials, and a 1-click "Copy Debug Info" button (never includes private keys or documents).
* New: Added quick "Settings" and "Knowledge Base" shortcuts directly on the Plugins page.
* Improved: Up to 10x faster uploads for large documentation files with automatic background optimization and live "Optimizing..." / "Ready" status indicators.
* Improved: Smarter AI question understanding so visitors get accurate answers even when they use casual wording or synonyms.

= 1.0.1 =
* Improved: Better reading of Markdown dividers, tables, lists, and section headings so "Read more" links jump directly to the right section.
* Improved: Added daily usage safeguards and strengthened API key security for Google Gemini.
* Fixed: Better compatibility when placing multiple InfiniBase FAQ sections on the same page.

= 1.0.0 =
* Initial release of InfiniBase: AI FAQ & Knowledge Base.

== Upgrade Notice ==

= 1.0.2 =
A brand-new admin design, smarter AI question matching, 10x faster large-document uploads, and a 1-click Test Connection tool.

== Credits ==

* Bundled admin fonts: [Plus Jakarta Sans](https://github.com/tokotype/PlusJakartaSans) and [JetBrains Mono](https://www.jetbrains.com/lp/mono/), both licensed under the [SIL Open Font License 1.1](https://openfontlicense.org/).
* Bundled Markdown parser: [Parsedown](https://parsedown.org/), licensed under the MIT License.
