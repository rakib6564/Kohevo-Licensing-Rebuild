<?php
/**
 * MCP Gateway — pack de langue français.
 * Enregistré via le filtre `i18n_lang_paths` dans McpGateway::boot().
 */

return [
    'dashboard' => 'Tableau de bord',
    'mcp_gateway_ai_settings_nav' => 'Connexion IA',
    'mcp_gateway_chat_nav' => 'Centre de commande IA',
    'mcp_gateway_nav' => 'Accès IA',

    // ── Partagé ──────────────────────────────────────────────────────────
    'mcp_gateway_security_check_failed' => 'Échec de la vérification de sécurité.',

    // ── admin/ai-settings.php ────────────────────────────────────────────
    'mcp_gateway_ai_settings_saved' => 'Réglages de connexion IA enregistrés.',
    'mcp_gateway_ai_settings_sub' => 'Connectez un fournisseur IA une seule fois — l\'assistant IA d\'administration et l\'assistant du site pour les clients l\'utilisent tous les deux.',
    'mcp_gateway_ai_settings_not_configured' => 'Pas encore configuré — renseignez les champs ci-dessous pour activer l\'assistant IA et l\'assistant du site pour les clients.',
    'mcp_gateway_ai_settings_provider_heading' => 'Fournisseur',
    'mcp_gateway_ai_settings_provider_desc' => 'Tout point de terminaison <code>/chat/completions</code> compatible OpenAI fonctionne ici — OpenAI lui-même, Anthropic via un proxy compatible, OpenRouter, une passerelle auto-hébergée, etc.',
    'mcp_gateway_ai_settings_base_url_label' => 'URL de base de l\'API',
    'mcp_gateway_ai_settings_base_url_hint' => 'Tout ce qui précède (sans l\'inclure) <code>/chat/completions</code>.',
    'mcp_gateway_ai_settings_model_label' => 'Modèle',
    'mcp_gateway_ai_settings_api_key_label' => 'Clé API',
    'mcp_gateway_ai_settings_key_saved_placeholder' => '••••••••  (enregistrée — laissez vide pour la conserver)',
    'mcp_gateway_ai_settings_remove_key' => 'Supprimer la clé enregistrée',
    'mcp_gateway_ai_settings_enable_customer_assistant' => 'Activer l\'assistant du site pour les clients',
    'mcp_gateway_ai_settings_customer_assistant_hint' => 'Répond aux questions des visiteurs sur les services, les forfaits et leur propre tableau de bord en utilisant uniquement le contenu de ce site — il ne voit jamais les données d\'administration et ne répond pas aux questions sans rapport.',
    'mcp_gateway_ai_settings_save_btn' => 'Enregistrer',
    'mcp_gateway_ai_settings_footer' => 'Le chat %1$s de l\'administration peut créer ou modifier des forfaits d\'adhésion, des services/praticiens de réservation, et plus encore via les mêmes outils MCP qu\'utiliserait un client IA externe — chaque action est confirmée avant son exécution et journalisée dans %2$s.',

    // ── admin/index.php (AI Access / jetons) ────────────────────────────
    'mcp_gateway_index_sub' => 'Créez des identifiants restreints et propres à ce site pour les agents IA autorisés (Claude, Manus, etc.).',
    'mcp_gateway_index_token_created' => 'Jeton créé. Copiez-le maintenant — Kohevo ne pourra plus l\'afficher.',
    'mcp_gateway_index_token_revoked' => 'Jeton révoqué immédiatement.',
    'mcp_gateway_index_disabled_notice' => 'La passerelle MCP est actuellement <strong>désactivée</strong> au niveau de l\'environnement (<code>MCP_GATEWAY_ENABLED</code> n\'est pas défini sur <code>1</code>). Les jetons peuvent toujours être créés et révoqués ici, mais aucun agent ne peut se connecter tant que ce paramètre n\'a pas été activé par la personne qui gère l\'environnement serveur.',
    'mcp_gateway_index_copy_token_heading' => 'Copiez ce jeton maintenant',
    'mcp_gateway_index_copy_token_desc' => 'Collez-le dans la configuration du connecteur/secret du client IA. Il n\'est affiché qu\'une seule fois et peut être révoqué ci-dessous.',
    'mcp_gateway_index_endpoint_info' => 'Point de terminaison : %1$s · à envoyer sous la forme %2$s',
    'mcp_gateway_index_create_token_heading' => 'Créer un jeton d\'accès IA',
    'mcp_gateway_index_token_safety_note' => 'Aucun jeton, quels que soient les droits sélectionnés, ne peut changer un mot de passe, supprimer un rôle ou un compte utilisateur, écrire une clé secrète Stripe/SMTP active, ou désactiver ce plugin — ces actions sont bloquées dans le code, indépendamment des droits choisis. Donnez à chaque client IA son propre jeton à accès minimal.',
    'mcp_gateway_index_label_field' => 'Libellé',
    'mcp_gateway_index_label_placeholder' => 'Assistant admin Claude',
    'mcp_gateway_index_expiry_label' => 'Date d\'expiration',
    'mcp_gateway_index_no_scopes' => 'Aucun droit n\'est encore enregistré — installez/activez un plugin fournissant des outils MCP (Réservation, Adhésion, etc.) pour voir des options ici.',
    'mcp_gateway_index_create_token_btn' => 'Créer le jeton',
    'mcp_gateway_index_issued_tokens_heading' => 'Jetons émis',
    'mcp_gateway_index_no_tokens' => 'Aucun jeton d\'accès IA n\'a été créé pour ce site.',
    'mcp_gateway_index_expires_fragment' => ' · expire le %s',
    'mcp_gateway_index_used_fragment' => ' · utilisé le %s',
    'mcp_gateway_index_never_used_fragment' => ' · jamais utilisé',
    'mcp_gateway_index_revoke_confirm' => 'Révoquer ce jeton IA immédiatement ?',
    'mcp_gateway_index_revoked_fragment' => 'Révoqué le %s',
    'mcp_gateway_index_footer' => 'Chaque appel effectué avec ces jetons — autorisé ou bloqué — est enregistré dans %s (filtrez par le préfixe d\'action <code>mcp-gateway.</code>).',

    // ── admin/chat.php ───────────────────────────────────────────────────
    'mcp_gateway_chat_title' => 'Assistant IA',
    'mcp_gateway_chat_sub' => 'Demandez-lui de rechercher des informations ou d\'apporter des modifications — toute action qui écrit des données vous montre d\'abord l\'appel exact.',
    'mcp_gateway_chat_clear_confirm' => 'Effacer cette conversation ?',
    'mcp_gateway_chat_clear_btn' => 'Effacer le chat',
    'mcp_gateway_chat_not_configured' => 'Le fournisseur IA n\'est pas encore configuré. Configurez-le dans %s d\'abord.',
    'mcp_gateway_chat_prompt_library_summary' => 'Bibliothèque de messages — cliquez sur un message pour le charger dans la zone de saisie',
    'mcp_gateway_chat_empty_state' => 'Demandez-lui quelque chose — par ex. « Créer un service de massage profond de 60 minutes à 90 EUR » ou « Lister les forfaits d\'adhésion actifs ».',
    'mcp_gateway_chat_tool_called' => '→ appel de %s',
    'mcp_gateway_chat_tool_result' => '✓ résultat : %s',
    'mcp_gateway_chat_declined_reason' => 'L\'administrateur a refusé cette action.',
    'mcp_gateway_chat_fill_details' => 'Renseignez les détails pour l\'exécuter :',
    'mcp_gateway_chat_review_confirm' => 'Vérifiez et confirmez avant l\'exécution :',
    'mcp_gateway_chat_no_input_needed' => 'Aucune saisie requise — prêt à s\'exécuter.',
    'mcp_gateway_chat_confirm_run_btn' => 'Confirmer et exécuter',
    'mcp_gateway_chat_input_placeholder' => 'Demandez à l\'assistant de rechercher quelque chose ou d\'apporter une modification…',
    'mcp_gateway_chat_send_btn' => 'Envoyer',
    'mcp_gateway_chat_running_label' => 'Exécution…',
    'mcp_gateway_chat_choose_placeholder' => '— choisir —',
    'mcp_gateway_chat_json_array_placeholder' => 'Tableau JSON',
    'mcp_gateway_chat_comma_separated_placeholder' => 'séparés par des virgules',

    // Bibliothèque de messages — titres de groupe
    'mcp_gateway_chat_group_membership' => 'Adhésion',
    'mcp_gateway_chat_group_booking' => 'Réservation',
    'mcp_gateway_chat_group_coaching' => 'Coaching',
    'mcp_gateway_chat_group_forms' => 'Formulaires',
    'mcp_gateway_chat_group_translation' => 'Traduction',
    'mcp_gateway_chat_group_studio_pages' => 'Pages Studio',
    'mcp_gateway_chat_group_core_system' => 'Cœur / Système',

    // Bibliothèque de messages — Adhésion
    'mcp_gateway_chat_prompt_membership_1' => 'Lister tous les forfaits d\'adhésion actifs.',
    'mcp_gateway_chat_prompt_membership_2' => 'Créer un forfait d\'adhésion nommé « Founding Member » à 99, spécifique à un cours, lié à [service name], 12 séances, 90 jours.',
    'mcp_gateway_chat_prompt_membership_3' => 'Afficher le statut d\'adhésion du client [email or id].',
    'mcp_gateway_chat_prompt_membership_4' => 'Attribuer le forfait [plan name] au client [email or id].',
    'mcp_gateway_chat_prompt_membership_5' => 'Lister tous les membres et leur forfait actuel.',

    // Bibliothèque de messages — Réservation
    'mcp_gateway_chat_prompt_booking_1' => 'Lister tous les services de réservation actifs avec leur prix et leur durée.',
    'mcp_gateway_chat_prompt_booking_2' => 'Créer un nouveau service de 45 minutes nommé « [name] » à [price].',
    'mcp_gateway_chat_prompt_booking_3' => 'Ajouter un nouveau praticien nommé « [name] » avec le fuseau horaire [Europe/Paris].',
    'mcp_gateway_chat_prompt_booking_4' => 'Afficher les créneaux disponibles pour [service name] le [YYYY-MM-DD].',
    'mcp_gateway_chat_prompt_booking_5' => 'Mettre à jour [service name] pour le lier au praticien [name].',

    // Bibliothèque de messages — Coaching
    'mcp_gateway_chat_prompt_coaching_1' => 'Lister les clients de coaching actuellement inscrits.',
    'mcp_gateway_chat_prompt_coaching_2' => 'Afficher la conversation avec le client [email or id].',
    'mcp_gateway_chat_prompt_coaching_3' => 'Envoyer ce message au client [email or id] : « [message] ».',
    'mcp_gateway_chat_prompt_coaching_4' => 'Créer un modèle de structure de repas « petit-déjeuner » intitulé « [title] » avec les notes : [notes].',
    'mcp_gateway_chat_prompt_coaching_5' => 'Créer une recette intitulée « [title] » avec les ingrédients : [ingredient list].',
    'mcp_gateway_chat_prompt_coaching_6' => 'Créer un modèle de liste de courses nommé « [name] » avec les sections : [heading: items].',

    // Bibliothèque de messages — Formulaires
    'mcp_gateway_chat_prompt_forms_1' => 'Lister tous les formulaires et le nombre de soumissions de chacun.',
    'mcp_gateway_chat_prompt_forms_2' => 'Créer un formulaire de contact intitulé « [title] » avec les champs : nom complet (texte, obligatoire), e-mail (obligatoire), message (zone de texte).',
    'mcp_gateway_chat_prompt_forms_3' => 'Afficher les dernières soumissions pour [form title].',
    'mcp_gateway_chat_prompt_forms_4' => 'Marquer la soumission n° [id] comme traitée.',

    // Bibliothèque de messages — Traduction
    'mcp_gateway_chat_prompt_translation_1' => 'Lister toutes les langues configurées.',
    'mcp_gateway_chat_prompt_translation_2' => 'Ajouter [Spanish] comme nouvelle langue.',
    'mcp_gateway_chat_prompt_translation_3' => 'Lister les chaînes non traduites contenant « [keyword] ».',
    'mcp_gateway_chat_prompt_translation_4' => 'Afficher les 20 premières chaînes qui ont encore besoin d\'une traduction en français.',
    'mcp_gateway_chat_prompt_translation_5' => 'Traduire la chaîne n° [id] en [French] : « [translated text] ».',
    'mcp_gateway_chat_prompt_translation_6' => 'Enregistrer cela comme brouillon, ne pas publier pour l\'instant.',
    'mcp_gateway_chat_prompt_translation_7' => 'Publier tous les brouillons de traduction en [French].',

    // Bibliothèque de messages — Pages Studio
    'mcp_gateway_chat_prompt_studio_1' => 'Lister les pages Studio et indiquer lesquelles ont des modifications en brouillon non publiées.',
    'mcp_gateway_chat_prompt_studio_2' => 'Afficher la structure de la page Studio « [title] ».',
    'mcp_gateway_chat_prompt_studio_3' => 'Sur la page Studio « [title] », remplacer le titre principal par « [new heading] » en tant que brouillon.',
    'mcp_gateway_chat_prompt_studio_4' => 'Ajouter une section avec un titre « [text] » et un paragraphe « [text] » à la fin de la page Studio « [title] » en tant que brouillon.',
    'mcp_gateway_chat_prompt_studio_5' => 'Afficher les différences entre le brouillon actuel de la page Studio « [title] » et sa version publiée.',
    'mcp_gateway_chat_prompt_studio_6' => 'Donner le lien d\'aperçu du brouillon actuel de la page Studio « [title] » pour que je puisse le vérifier et le publier.',

    // Bibliothèque de messages — Cœur / Système
    'mcp_gateway_chat_prompt_core_1' => 'Quels sont les réglages actuels du site ?',
    'mcp_gateway_chat_prompt_core_2' => 'Définir la devise du site sur [EUR].',
    'mcp_gateway_chat_prompt_core_3' => 'Afficher les 20 dernières entrées du journal d\'audit.',
    'mcp_gateway_chat_prompt_core_4' => 'Donnez-moi un rapport d\'activité pour ce mois-ci.',
    'mcp_gateway_chat_prompt_core_5' => 'Vérifier le statut des migrations de la base de données.',
];
