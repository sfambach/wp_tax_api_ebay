<?php declare(strict_types=1);

namespace TaxEbay\Core\OAuth;

use TaxEbay\Core\Api\Environment;

/**
 * The keys of his eBay developer app. They live in wp-config.php, never in the database (D-943).
 */
final class AppKeys
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $ruName,
        public readonly Environment $environment = Environment::Production,
    ) {
    }

    public function basicAuthorization(): string
    {
        return 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
    }
}
