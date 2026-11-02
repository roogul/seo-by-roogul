# SEO by ROOGUL

Straightforward SEO for Craft CMS 5:

- **Global defaults per site** that editors can change on production.
- **An SEO field** with per-entry overrides, fallbacks and a live search preview.
- **Automatic output** of the title, meta, canonical and hreflang tags, Open Graph, X cards and JSON-LD.
- **XML sitemaps** and an editable **robots.txt**.

There's no keyword scoring, redirect manager or schema builder.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require roogul/seo-by-roogul
php craft plugin/install seo-by-roogul
```

## Setup

1. **Make sure your layout calls `{{ head() }}`** in `<head>`, and **remove your own `<title>` tag**. The plugin outputs the title.
2. **SEO → Globals:** set the site name, title template, default description and share image, and the organisation details. On a multi-site install, blank fields on other sites fall back to the primary site.
3. **Add the SEO field** to your entry types, category groups or product types. In the field's settings, list fallback fields. For example:
   - Title: `title`
   - Description: `summary, excerpt, body`
   - Image: `heroImage, featuredImage`
4. **Settings → Plugins → SEO by ROOGUL:** choose which sections appear in the sitemap. Then **delete any physical `web/robots.txt`**, or your web server will serve it instead of the plugin's.

## How values are chosen

Each value uses the first of these that has content:

| | Title | Description | Image |
|---|---|---|---|
| 1 | `craft.seoByRoogul.set()` | `set()` | `set()` |
| 2 | SEO field | SEO field | SEO field |
| 3 | Fallback fields | Fallback fields | Fallback fields |
| 4 | Element title | Global default | Global default |
| 5 | Site name | none | none |

- **Titles** get the global title template (`{title} | {siteName}`) unless *Use title exactly as written* is on. The homepage uses just the site name unless an SEO title is set; a set title gets the template like any other page.
- **Descriptions** have HTML stripped and are trimmed to 160 characters.
- **Share images** are cropped to 1200×630. You can change the size in the plugin settings.

## Indexing

Pages get `noindex, nofollow` when any of these apply:
- the environment isn't production (see below)
- the response is an error, such as a 404
- the site's *Hide from search engines* switch is on
- the entry's SEO field has noindex set

Production means `CRAFT_ENVIRONMENT` matches *Production environments* in the plugin settings (default `production`). If `CRAFT_ENVIRONMENT` isn't set, indexing is allowed.

Non-production environments also get a disallow-all robots.txt and an `X-Robots-Tag` header, so staging stays out of search results.

## Sitemaps

- `/sitemap.xml` is an index linking to one sitemap per section, category group or Commerce product type. Singles are grouped together.
- Each sitemap holds up to 1,000 URLs per page.
- Entries marked noindex, or with a canonical URL pointing elsewhere, are left out.
- Sitemaps are cached and cleared whenever an entry, category or product is saved or deleted.
- On multi-site installs, each site has its own `sitemap.xml` under its base URL.

If you use Blitz, exclude `sitemap*.xml` and `robots.txt` from caching, or let Blitz refresh them alongside your entries.

## Templates

Override values for a page, for example a search results template with no entry behind it:

```twig
{% do craft.seoByRoogul.set({
  title: 'Search results',
  description: 'Search everything on the site.',
  noindex: true,
}) %}
```

The supported keys are:

| Key | Notes |
|---|---|
| `element` | Use this element's SEO values on a custom route |
| `title` | |
| `rawTitle` | Skip the title template |
| `description` | |
| `image` | An Asset or a URL |
| `canonical` | |
| `noindex` | |
| `nofollow` | |
| `type` | The `og:type` value |

`set()` works from anywhere in the template, including inside blocks, because the tags are added after the page has rendered.

Add your own JSON-LD nodes to the page's `@graph`:

```twig
{% do craft.seoByRoogul.schema({
  '@type': 'Product',
  name: product.title,
  offers: { '@type': 'Offer', price: product.defaultPrice, priceCurrency: 'GBP' },
}) %}
```

Other helpers:

```twig
{{ craft.seoByRoogul.render() }}     {# all tags, when auto-inject is off #}
{% set meta = craft.seoByRoogul.meta() %}  {# resolved title, description, image, canonical… #}
{{ craft.seoByRoogul.globals().siteName }}
{{ craft.seoByRoogul.sitemapUrl() }}
```

Read an entry's SEO field values directly with `entry.seo.title`, `entry.seo.description` and `entry.seo.getImage()`. This assumes the field handle is `seo`.

## What's output

- `<title>`, meta description and meta robots.
- A canonical link, plus hreflang alternates and `x-default` for sites in the same site group.
- Open Graph tags: type, site name, title, description, URL, locale, and the image with its size and alt text.
- An X card, plus `twitter:site` when a handle is set.
- JSON-LD containing:
  - WebSite
  - Organisation or Person
  - WebPage, with dates for entries
  - BreadcrumbList, built from the structure's ancestors where there are any

## Permissions

*Edit global SEO settings* controls access to SEO → Globals. On multi-site installs, users also need permission to edit the site.

## Config file

Override plugin settings per environment in `config/seo-by-roogul.php`:

```php
<?php

return [
    '*' => [
        'productionEnvironments' => 'production',
    ],
];
```

Globals are content, so they live in the database rather than project config, and editors can change them on production.
