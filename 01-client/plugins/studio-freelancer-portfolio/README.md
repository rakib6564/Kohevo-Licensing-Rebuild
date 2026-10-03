# Studio Freelancer Portfolio Plugin

Modern, elegant freelancer portfolio website kit and custom widgets for **Kohevo Studio Builder**.

## Overview

This plugin extends Kohevo Studio Builder with custom widgets and templates designed for modern high-end freelancer portfolios, product design consultants, and full-stack software architects.

## Included Custom Widgets

1. **`portfolio.project_card`**:
   - High-fidelity project case study card with cover image preview, hover micro-transitions, category eyebrow, title, excerpt, metric badge (e.g. `+185% Daily Active Users`), and tech tags.
2. **`portfolio.stat_highlight`**:
   - Gradient-accented metric counter card (e.g. `9+ Years`, `42+ Products Shipped`).
3. **`portfolio.skill_grid`**:
   - Clean domain-grouped skill tags (Frontend, Backend, Design Systems).
4. **`portfolio.experience_timeline`**:
   - Career milestone card with role, company, timeframe, and highlights.
5. **`portfolio.service_card`**:
   - Numbered service capability card with index, title, and description.
6. **`portfolio.testimonial_card`**:
   - Client endorsement card with 5-star rating, testimonial quote, client avatar, name, and role.

## Canonical Portfolio Document Generator

The plugin provides `StudioFreelancerPortfolio::getPortfolioDocument()` to instantly generate a complete, production-ready canonical document featuring:
- Hero with live availability badge, bold typography, CTA action buttons, and stat highlights.
- Selected Work grid with custom project cards.
- Areas of Expertise / Services grid.
- Core Stack & Technologies cards.
- Client Endorsements using `portfolio.testimonial_card`.
- Consultation Inquiry Form using `core.form` & `core.form_field`.

## Usage via CLI

Generate or publish the portfolio on any tenant:
```bash
php bin/create-freelancer-portfolio.php
```
