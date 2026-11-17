<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use Taxmod\Core\Http\HttpRequest;
use Taxmod\Core\Http\HttpResponse;
use Taxmod\Core\Http\HttpTransport;

/**
 * Answers from a script and remembers what was asked.
 */
final class ScriptedTransport implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $sent = [];

    /** @param list<HttpResponse> $answers */
    public function __construct(private array $answers)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->sent[] = $request;

        return array_shift($this->answers) ?? new HttpResponse(500, '');
    }
}
