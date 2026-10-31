<?php
/**
 * Forms integrations — what an email marketing service must do to receive a form's signups.
 *
 * Add a service by implementing this and returning it from the `forms_newsletter_connectors` filter;
 * the Integrations screen and the delivery queue need no change.
 */

declare(strict_types=1);

interface NewsletterConnector {
    /** Lowercase key stored with each rule, e.g. `brevo`. */
    public function key(): string;

    public function label(): string;

    /** The service's contact lists: [['id' => int, 'name' => string], ...]. Throws IntegrationException on failure. */
    public function lists(string $apiKey): array;

    /**
     * Add one contact to a list. Throws IntegrationException on failure.
     *
     * @param array<string, string> $attributes FIRSTNAME and so on
     * @param array{template_id?: int, redirect_url?: string} $doubleOptin empty sends no confirmation email
     */
    public function subscribe(string $apiKey, int $listId, string $email, array $attributes, array $doubleOptin): void;
}

class IntegrationException extends RuntimeException {
    /** @param bool $retry false when sending the same thing again cannot work (a bad address, a bad key) */
    public function __construct(string $message, public readonly bool $retry = true) {
        parent::__construct($message);
    }
}
