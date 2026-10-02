# Studio Testimonial Widget

Example third-party plugin demonstrating the Kohevo Studio Developer Widget SDK (Sprint 8 / Section 31 & 64).

## Features

- Registers a custom visual widget (`acme.testimonial`) into the Studio Builder palette without touching core files.
- Defines a typed `FieldSchema` for props: author, role, company, quote, avatar_url, and rating.
- Implements a server-side renderer outputting accessible HTML with quotation and star rating markup.
- Implements version migration support (v1 -> v2) for upgrading documents safely.

## Architecture

This plugin boots into Kohevo and calls:

```php
\Slate\Module\StudioBuilder\Sdk\Studio::widgets()->register([...]);
```

The widget is automatically discovered by Studio Builder's `BlockRegistry`, `BlockRendererRegistry`, and `WidgetRegistry`.
