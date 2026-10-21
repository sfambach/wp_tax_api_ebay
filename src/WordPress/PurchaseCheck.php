<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use DateTimeImmutable;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\OAuth\TokenClient;
use TaxEbay\Core\Purchases\PurchaseMapper;
use TaxEbay\Core\Purchases\PurchasesClient;
use TaxMail\WordPress\ModelGateway;
use TaxMail\WordPress\Targets;
use WP_Error;

/**
 * The first step of D-950: every purchase eBay returns, each marked «in the model» or «missing». Reads only; nothing
 * is written. The model is asked through tax_mail's gateway — the same lookup by platform and order number the mail
 * import uses, so both modules call the same order a duplicate.
 */
final class PurchaseCheck
{
    /** The last result, per user, so the tab can show it after the redirect. */
    private const RESULT = 'taxebay_purchase_check_';

    /**
     * @param array{refresh: string} $connection
     * @return array{checked_at: string, model: bool, rows: list<array{proposal: array<string, mixed>, known: ?bool}>}
     */
    public function run(AppKeys $keys, array $connection): array
    {
        $http = new WpHttpTransport();
        $token = (new TokenClient($keys, $http))->refresh(Settings::vault()->open($connection['refresh']), time())->accessToken;
        $to = new DateTimeImmutable('now');
        $from = $to->modify('-' . (PurchasesClient::MAX_DAYS_BACK - 1) . ' days');
        $orders = (new PurchasesClient($keys->environment, $http))->ordersCreatedBetween($token, $from, $to);

        $gateway = class_exists(ModelGateway::class) && ModelGateway::available() ? new ModelGateway(Targets::load()) : null;
        $mapper = new PurchaseMapper();
        $rows = [];

        foreach ($orders as $order) {
            if ($mapper->isCancelled($order)) {
                continue;
            }

            $rows[] = ['proposal' => $mapper->map($order), 'known' => $gateway === null ? null : $this->known($gateway, $mapper->orderNumbers($order))];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['proposal']['order_date'], (string) $a['proposal']['order_date']));

        return ['checked_at' => current_time('mysql'), 'model' => $gateway !== null, 'rows' => $rows];
    }

    /** @param list<string> $numbers */
    private function known(ModelGateway $gateway, array $numbers): bool
    {
        foreach ($numbers as $number) {
            $found = $gateway->findOrder(PurchaseMapper::PLATFORM, $number);

            if ($found instanceof WP_Error) {
                throw new EbayApiException('Reading the model: ' . $found->get_error_message());
            }

            if ($found !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $result */
    public static function remember(array $result): void
    {
        set_transient(self::RESULT . get_current_user_id(), $result, DAY_IN_SECONDS);
    }

    /** @return array<string, mixed>|null */
    public static function last(): ?array
    {
        $result = get_transient(self::RESULT . get_current_user_id());

        return is_array($result) ? $result : null;
    }
}
