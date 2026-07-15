=== Pageking Schema Plugin ===
Contributors: aaronmeeuspk
Tags: schema, seo, structured data, json-ld
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Analyseert content per post type en bouwt automatisch Schema.org markup (JSON-LD) voor zoekmachines.

== Description ==

Detecteert welke post types op een site bestaan, laat per post type kiezen welk Schema.org-type erbij hoort (Product, JobPosting, Article, WebPage, Service, Person, Organization, FAQPage, Review), en genereert automatisch JSON-LD op basis van kernvelden, ACF, taxonomieën, WooCommerce, actieve SEO-plugins en WPML-taalinfo.

Belangrijkste onderdelen:

* Instellingenpagina om post types te activeren en een Schema-type te kiezen, met een slimme suggestie per post type.
* Veldmapping voor site-specifieke data (salaris, sluitingsdatum, merk, etc.) — zowel ACF-velden als taxonomieën, met een ingebouwde kandidatenlijst-gok als niets gemapt is.
* Detecteert conflicten met schema-functionaliteit van actieve SEO-plugins (SEOPress, Yoast, RankMath).
* Interne validatie tegen Google's gedocumenteerde verplichte/aanbevolen velden per Schema-type.
* Caching van de duurdere review/FAQ-aggregatie, automatisch ongeldig bij wijzigingen.

== Installation ==

1. Upload de map `pk-schema-plugin` naar `/wp-content/plugins/`.
2. Activeer de plugin via het menu 'Plugins' in WordPress.
3. Ga naar 'Schema Plugin' in het wp-admin-menu om post types te activeren en Schema-types te kiezen.

== Changelog ==

= 0.1.3 =
* readme.txt zat er in 0.1.2 nog niet echt in (verkeerd zonder versiebump toegevoegd) — nu met een échte versiebump, zodat sites die al op 0.1.2 stonden de changelog alsnog binnenkrijgen bij deze update.

= 0.1.2 =
* Taxonomieën toegevoegd als bron voor veldmapping, naast ACF-velden (bv. merk als taxonomie-term i.p.v. los veld).

= 0.1.1 =
* Toegang tot de plugin beperkt tot echte Administrators (i.p.v. manage_options, dat door sommige plugins ook aan andere rollen wordt toegekend).
* Uninstall-routine toegevoegd die alle opties en caches opruimt.
* Auto-update via GitHub getest en bevestigd werkend.

= 0.1.0 =
* Eerste versie: databerzameling (core, ACF, taxonomieën, WooCommerce, SEO-plugins, WPML), instellingenpagina, en Schema-generator voor Product, JobPosting, Article, WebPage, Service, Person, Organization, FAQPage en Review.
