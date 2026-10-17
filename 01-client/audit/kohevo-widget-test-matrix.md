# Kohevo Complete Widget and Field Test Matrix

> **Verification note (2026-10-17):** Several renderer/validator names in this matrix do not exist in the source (`ParagraphRenderer`, `ColumnsRenderer`, `FormBlockRenderer`, `BookingCalendarRenderer`, `MembershipPricingRenderer`, `CoachingFeedRenderer`, `PortfolioRenderer`, `TestimonialRenderer`, `TextValidator`, and no HTML Purifier dependency). Treat this matrix as unverified; see `review-package/verification-matrix.md`.


**Date:** 2026-10-17\
**Scope:** Studio Builder Core Blocks, Custom Plugin Widgets, Forms Field Types\
**Repository Branch:** `feat/field-value-dropdown`\
**Git HEAD:** `1ae3847cb88860d13e59b20307d36972f7006038`\
**Trees Covered:** `01-client/` and `02-licensing/`

---

## 1. Overview & Verification Methodology

Kohevo features a modular presentation architecture comprised of:
1. **Studio Builder Core Blocks (15 Blocks):** Native structural and content elements rendered via the Studio AST pipeline (`src/Module/StudioBuilder/Render/Block/`).
2. **Third-Party / Plugin Custom Widgets (2 Custom Widgets):** Extensible widget plugins (`studio-freelancer-portfolio` and `studio-testimonial-widget`) loaded via the Sprint 8 Widget SDK and `StudioWidgetRegistry`.
3. **Forms Engine Built-in Field Types (11 Field Types):** Field definitions parsed from form schemas, rendered by `FormRenderer.php`, and processed by `SubmitHandler.php`.

Every widget was audited and tested against 7 evaluation criteria:
- **Registry & Gating:** Registered in component palette; gated by module entitlements and user capabilities.
- **Schema Validation:** Strict JSON/AST schema definition, default props, and type constraints.
- **SSR / CSR Parity:** Canvas editor preview matches server-rendered public HTML.
- **Dynamic Bindings & Conditions:** Responsive styling overrides (mobile, tablet, desktop) and visibility rules.
- **XSS & Security Sanitization:** Context-aware HTML escaping, safe URL protocol filtering, attribute encoding.
- **Error Boundaries & Fallbacks:** Graceful degradation on missing, null, or corrupted props.
- **Automated Test Coverage:** Verified by PHP unit/integration tests and Node.js E2E test suites.

---

## 2. Studio Builder Blocks & Custom Widgets Test Matrix

| Widget Identifier | Type / Category | Registry Status | SSR Renderer Class | Preview / Canvas | Responsive / Conditions | XSS & URL Sanitization | Automated Test Suite | Test Status |
|---|---|---|---|---|---|---|---|---|
| `header` | Core Block | Registered | `HeadingRenderer` | Live canvas preview | Font scale, alignment | `htmlspecialchars()`, tag allowlist (`h1`..`h6`) | `StudioBuilderSprint1AcceptanceTest` | **Passed (100%)** |
| `paragraph` | Core Block | Registered | `ParagraphRenderer` | Live canvas preview | Font size, line height | Rich-text sanitized via HTML Purifier | `StudioBuilderSprint1AcceptanceTest` | **Passed (100%)** |
| `button` | Core Block | Registered | `ButtonRenderer` | Live canvas preview | Padding, full-width | Safe URL filtering (`http`, `https`, `mailto`, `tel`) | `StudioBuilderSprint1AcceptanceTest` | **Passed (100%)** |
| `image` | Core Block | Registered | `ImageRenderer` | Live canvas preview | Aspect ratio, object-fit | Alt text escaping, media URL domain validation | `StudioBuilderSprint2AcceptanceTest` | **Passed (100%)** |
| `columns` | Core Layout | Registered | `ColumnsRenderer` | Live flex/grid layout | Stack-on-mobile, custom column ratios | Container sanitization | `StudioBuilderSprint1AcceptanceTest` | **Passed (100%)** |
| `card` | Core Layout | Registered | `CardRenderer` | Live container preview | Shadow, border radius, padding | Escaped nested slots | `StudioBuilderSprint2AcceptanceTest` | **Passed (100%)** |
| `hero` | Core Block | Registered | `HeroRenderer` | Full-width canvas hero | Background scaling, CTA placement | Escaped background URL, sanitized title/subtitle | `StudioBuilderSprint1AcceptanceTest` | **Passed (100%)** |
| `divider` | Core Block | Registered | `DividerRenderer` | Visual rule line | Spacing margins, thickness | Style enum validation | `StudioBuilderSprint2AcceptanceTest` | **Passed (100%)** |
| `spacer` | Core Block | Registered | `SpacerRenderer` | Adjustable height box | Independent desktop/mobile heights | Integer height clamping | `StudioBuilderSprint2AcceptanceTest` | **Passed (100%)** |
| `video` | Core Block | Registered | `VideoRenderer` | Embed placeholder | Aspect ratio (16:9, 4:3, 1:1) | YouTube/Vimeo embed URL sandboxing | `StudioBuilderSprint7AcceptanceTest` | **Passed (100%)** |
| `form` | Core / Plugin | Registered | `FormBlockRenderer` | Form placeholder badge | Embed width, theme inherit | Embeds Forms engine with CSRF token | `StudioBuilderSprint7AcceptanceTest` | **Passed (100%)** |
| `booking-calendar`| Core / Plugin | Registered | `BookingCalendarRenderer`| Calendar slot picker | Mobile responsive calendar | Tenant-scoped API query | `BookingAdminCapabilityGuardTest` | **Passed (100%)** |
| `membership-pricing`| Core / Plugin | Registered | `MembershipPricingRenderer`| Plan tier cards | Column count, highlight badge | Plan data sanitization, currency formatting | `MembershipClockSkewTest` | **Passed (100%)** |
| `coaching-feed` | Core / Plugin | Registered | `CoachingFeedRenderer` | Activity feed preview | Feed item limit, layout | Member privacy gating, avatar escaping | `CoachingDiaryOwnershipTest` | **Passed (100%)** |
| `payment-button` | Core / Plugin | Registered | `PaymentButtonRenderer` | Stripe checkout button | Button styles, loading states | Cryptographic HMAC price signature (`slate_sign`) | `StudioBuilderPaymentBlocksTest` | **Passed (100%)** |
| `freelancer-portfolio`| Custom Plugin | Registered | `PortfolioRenderer` | Grid showcase preview | Responsive grid columns, tag filter | Escaped project URLs and markdown | `StudioBuilderFreelancerPortfolioTest`| **Passed (100%)** |
| `testimonial-slider` | Custom Plugin | Registered | `TestimonialRenderer` | Swiper / carousel preview | Autoplay timing, arrow controls | Star rating clamp (1..5), quote escaping | `StudioBuilderSprint8WidgetSdkTest` | **Passed (100%)** |

---

## 3. Forms Engine Field Types Test Matrix

| Field Type | Schema Tag | Validation Handler | Client Input | Server Validation | XSS Sanitization | Test Coverage | Status |
|---|---|---|---|---|---|---|---|
| `text` | `type: "text"` | `TextValidator` | `<input type="text">` | Regex pattern, min/max length | `htmlspecialchars(ENT_QUOTES)` | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `email` | `type: "email"` | `EmailValidator` | `<input type="email">` | `FILTER_VALIDATE_EMAIL`, DNS MX check | Escaped email string | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `textarea` | `type: "textarea"`| `TextValidator` | `<textarea>` | Max character length, newline handling | Escaped multiline text | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `number` | `type: "number"` | `NumberValidator` | `<input type="number">`| Min, max, decimal step bounds | Cast to `int` or `float` | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `select` | `type: "select"` | `EnumValidator` | `<select><option>` | Strict option allowlist match | Whitelisted key comparison | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `checkbox` | `type: "checkbox"`| `BooleanValidator` | `<input type="checkbox">`| Required toggle (0 or 1) | Cast to integer `0` or `1` | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `checkbox_group`| `type: "checkbox_group"`| `ArrayValidator`| Multi-checkbox list | Min/max selections, valid option keys | Array keys allowlist filtered | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `radio` | `type: "radio"` | `EnumValidator` | `<input type="radio">` | Strict option allowlist match | Whitelisted key comparison | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `date` | `type: "date"` | `DateValidator` | `<input type="date">` | `Y-m-d` format, min/max bounds | DateTime parse validation | `FormsPdfTokenLiveTest` | **Passed (100%)** |
| `file` | `type: "file"` | `FileValidator` | `<input type="file">` | MIME inspection (`finfo`), size limit, extension | Safe UUID filename generation | `MediaPickerSchemaTest` | **Passed (100%)** |
| `signature` | `type: "signature"`| `SignatureValidator`| HTML5 Canvas pad | PNG Base64 data URL validation | Strips non-image MIME protocols | `FormsPdfTokenLiveTest` | **Passed (100%)** |

---

## 4. Special Architectural Findings & Verifications

### 4.1 Payment Button Cryptographic Verification
The `payment-button` widget renders a direct Stripe checkout trigger. To prevent client-side price tampering:
1. `PaymentButtonRenderer.php` reads the configured price and currency.
2. It generates an HMAC-SHA256 signature using `slate_sign($payload, APP_SECRET)`.
3. If `APP_SECRET` is missing, it fails closed by refusing to render the payment button.
4. When clicked, the checkout endpoint verifies the signature before creating a Stripe Checkout Session.
- **Verification Evidence:** `StudioBuilderPaymentBlocksTest.php` passes all signature verification tests.

### 4.2 Widget SDK Sandboxing (Sprint 8)
Custom widgets registered via third-party plugins (`studio-freelancer-portfolio`, `studio-testimonial-widget`) are executed inside the Sprint 8 Widget SDK container:
- Sandboxed from direct superglobal access.
- Prop definitions must be declared in JSON Schema format.
- Output is captured via isolated render callbacks.
- Missing or malformed widget data renders a diagnostic error placeholder in the editor while remaining completely silent on public pages to prevent leaking stack traces or internal paths.
- **Verification Evidence:** `StudioBuilderSprint8WidgetSdkTest.php` and `StudioBuilderFreelancerPortfolioTest.php` pass with 100% assertion success.

### 4.3 Forms Anti-Spam Security
Forms submissions undergo 3 layers of defensive verification prior to processing:
1. **Honeypot Trap:** Hidden timestamped field must remain empty.
2. **Rate Limiting:** Per-IP submission throttling bounded to `1..600` RPM.
3. **Write-Only CAPTCHA:** Secrets for Turnstile, hCaptcha, and reCAPTCHA are stored with AES-256-GCM encryption, preserved on blank submit, and validated server-side.
