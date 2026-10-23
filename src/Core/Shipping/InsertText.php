<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * The words on the parcel insert, in the buyer's language — not the admin's: the reader is the buyer. German for the
 * German-speaking countries, English for everyone else.
 */
final class InsertText
{
    private const GERMAN = ['DE', 'AT', 'CH', 'LI', 'LU'];

    /** @return array{thanks: string, docs: string, private: string, enjoy: string, order: string, seller: string, project: string} */
    public static function for(string $countryCode): array
    {
        return in_array(strtoupper($countryCode), self::GERMAN, true)
            ? [
                'thanks'  => 'Vielen Dank für deinen Kauf!',
                'docs'    => 'Dokumentation, Quellen und weitere Projekte:',
                'private' => 'Privatverkauf. Gibt es ein Problem, schreib mir über eBay – wir finden eine Lösung.',
                'enjoy'   => 'Viel Spaß damit!',
                'order'   => 'Bestellung',
                'seller'  => 'Verkäufer',
                'project' => 'Projekt (Schaltplan, Firmware, Anleitung):',
            ]
            : [
                'thanks'  => 'Thank you for your purchase!',
                'docs'    => 'Documentation, sources and more projects:',
                'private' => 'Private sale. If anything is wrong, write to me on eBay — we will find a solution.',
                'enjoy'   => 'Have fun with it!',
                'order'   => 'Order',
                'seller'  => 'Seller',
                'project' => 'Project (schematics, firmware, instructions):',
            ];
    }
}
