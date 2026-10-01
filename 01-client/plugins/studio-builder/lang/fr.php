<?php
/**
 * Kohevo Studio (studio-builder) — pack de langue français.
 * Enregistré via le filtre `i18n_lang_paths` dans StudioBuilder::boot().
 */

declare(strict_types=1);

return [
    'actions' => 'Actions',

    // ── Navigation & admin/index.php ─────────────────────────────────────
    'studio_pages' => 'Pages Studio',
    'studio_pages_subtitle' => 'Pages conçues avec Kohevo Studio. Ouvrez-en une pour la modifier dans l\'éditeur.',
    'studio_pages_forbidden' => 'Kohevo Studio n\'est pas disponible pour votre compte sur ce site.',
    'studio_pages_unavailable' => 'Les pages Studio n\'ont pas pu être chargées.',
    'studio_page_invalid' => 'La page n\'a pas pu être créée.',
    'studio_page_create_denied' => 'Vous ne pouvez pas créer de pages Studio ici.',
    'studio_new_page' => 'Nouvelle page',
    'studio_new_page_sub' => 'Donnez-lui un titre et une adresse, puis ouvrez-la dans l\'éditeur.',
    'studio_title_placeholder' => 'À propos',
    'studio_slug_hint' => 'Lettres minuscules, chiffres et tirets. Laissez vide pour utiliser le titre.',
    'studio_slug' => 'Adresse (slug)',
    'studio_page_type' => 'Type',
    'studio_type_page' => 'Page',
    'studio_type_landing' => 'Page d\'atterrissage',
    'studio_type_header' => 'En-tête du site',
    'studio_type_footer' => 'Pied de page du site',
    'studio_route_mode' => 'Route',
    'studio_route_standalone' => 'Sa propre adresse',
    'studio_route_homepage' => 'Page d\'accueil du site',
    'studio_template' => 'Partir d\'un modèle',
    'studio_template_blank' => 'Page vierge',
    'studio_create_and_open' => 'Créer et ouvrir l\'éditeur',
    'studio_no_pages' => 'Aucune page Studio pour le moment',
    'studio_no_pages_hint' => 'Créez une page ci-dessus pour l\'ouvrir dans l\'éditeur.',
    'studio_address' => 'Adresse',
    'studio_status_published' => 'Publiée',
    'studio_status_draft' => 'Brouillon',
    'studio_selected' => 'sélectionnée(s)',
    'studio_clear_selection' => 'Effacer la sélection',
    'studio_select_all_pages' => 'Sélectionner toutes les pages',
    'studio_select_page' => 'Sélectionner la page %s',
    'studio_unpublished_changes' => 'modifications non publiées',
    'studio_open_builder' => 'Ouvrir l\'éditeur',
    'studio_preview' => 'Aperçu',

    // ── admin/builder.php ────────────────────────────────────────────────
    'studio_builder' => 'Kohevo Studio',
    'studio_builder_forbidden' => 'Vous n\'avez pas accès à la modification de cette page.',
    'studio_builder_not_found' => 'Cette page est introuvable.',
    'studio_builder_unavailable' => 'L\'éditeur ne peut pas être ouvert pour le moment.',
    'studio_back_to_pages' => 'Retour aux pages Studio',
    'studio_builder_needs_js' => 'Kohevo Studio nécessite l\'activation de JavaScript.',
    'studio_builder_loading' => 'Chargement de l\'éditeur…',

    // ── Builder: import HTML/CSS (Phase 8B) ──────────────────────────────
    'studio_ui_import_source' => 'Importer depuis',
    'studio_ui_import_source_package' => 'Paquet Kohevo (.json)',
    'studio_ui_import_source_html' => 'HTML/CSS',
    'studio_ui_import_html_hint' => 'Choisissez un fichier HTML et, si besoin, un fichier CSS. La structure de la page est convertie en blocs Studio : les scripts, formulaires, contenus intégrés et styles non pris en charge sont supprimés, les images externes ne sont jamais téléchargées et rien n\'est publié.',
    'studio_ui_import_html_file' => 'Fichier HTML',
    'studio_ui_import_css_file' => 'Fichier CSS (facultatif)',
    'studio_ui_import_title' => 'Titre de la page',
    'studio_ui_import_slug' => 'Adresse de la page (slug)',
    'studio_ui_import_html_conversion' => '{stripped} élément(s) supprimé(s) par sécurité, {unsupported} élément(s) non pris en charge et {hidden} élément(s) masqué(s) ignoré(s).',
    'studio_ui_source_empty' => 'Le fichier est vide.',
    'studio_ui_source_too_large' => 'Ce fichier est trop volumineux pour être importé.',
    'studio_ui_seo_title_label' => 'Titre SEO',
    'studio_ui_seo_description_label' => 'Méta-description',
    'studio_ui_seo_canonical_label' => 'URL canonique',
    'studio_ui_seo_canonical_hint' => 'Facultatif. Un chemin de ce site (/a-propos) ou une adresse complète de ce site. Laissez vide pour utiliser l\'adresse propre de cette page. Les autres sites web sont ignorés : Studio ne déclare jamais un autre domaine comme canonique.',
    'studio_ui_seo_canonical_invalid' => 'Saisissez un chemin commençant par / ou une adresse http(s) complète.',
    'studio_ui_seo_og_image_label' => 'Image de partage social',
    'studio_ui_seo_og_image_hint' => 'Affichée lors du partage de la page. Choisissez une image dans votre médiathèque.',
    'studio_ui_seo_robots_label' => 'Visibilité dans les moteurs de recherche',
    'studio_ui_seo_draft_note' => 'Les réglages de recherche font partie du brouillon. Ils ne sont en ligne qu\'après la publication.',
    'studio_ui_seo_remove_image' => 'Retirer l\'image',

    // Phase 9A — strings Studio itself writes into PUBLIC pages (Html::t()). A
    // public page follows the tenant's site language, so a French site gets these.
    'studio_learn_more' => 'En savoir plus',
    'studio_book_now' => 'Réserver',
    'studio_join_now' => 'Adhérer',
    'studio_open_form' => 'Ouvrir le formulaire',
    'studio_no_services' => 'Aucun service n\'est disponible pour le moment.',
    'studio_no_plans' => 'Aucune formule n\'est disponible pour le moment.',
    'studio_media_unavailable' => 'Image indisponible',
    'studio_data_unavailable' => 'Données indisponibles dans ce contexte',
    'studio_block_unavailable' => 'Ce bloc est indisponible',
];
