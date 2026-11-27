<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\PayuApiResponse;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Parses a raw PayU postservice response body into a PayuApiResponse. PayU UAT
 * postservice returns PHP print_r output wrapped in <pre>…</pre> (NOT JSON) for
 * some commands (notably get_split_info), and JSON for others. This detects the
 * shape and flattens the top-level scalar fields. Mirrors the parser proven live
 * in tools/payu-lab (PayuResponse::parse, experiment E-01, 2026-06-08).
 */
class PayuResponseParser
{
    /**
     * @param Json $json
     */
    public function __construct(
        private readonly Json $json
    ) {
    }

    /**
     * @param string $rawBody The raw HTTP response body from PayU
     * @return PayuApiResponse
     */
    public function parse(string $rawBody): PayuApiResponse
    {
        $trimmed = trim($rawBody);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = $this->tryDecodeJson($trimmed);
            if (is_array($decoded)) {
                return PayuApiResponse::fromFields($this->flattenScalars($decoded), 'json', $decoded);
            }
        }

        if (preg_match('/Array\s*\(/', $trimmed)) {
            return PayuApiResponse::fromFields($this->parsePrintR($trimmed), 'print_r', $trimmed);
        }

        return PayuApiResponse::fromFields([], 'unknown', $rawBody);
    }

    /**
     * @param string $json
     * @return mixed Decoded value, or null when the body is not valid JSON
     */
    private function tryDecodeJson(string $json): mixed
    {
        try {
            return $this->json->unserialize($json);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<mixed> $decoded
     * @return array<string, string> Top-level scalar fields only
     */
    private function flattenScalars(array $decoded): array
    {
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_scalar($value)) {
                $out[(string)$key] = (string)$value;
            }
        }
        return $out;
    }

    /**
     * Extract top-level scalar lines ("    [key] => value", value not "Array") from a
     * print_r dump. Nested arrays stay in the raw body for deeper inspection downstream.
     *
     * @param string $body
     * @return array<string, string>
     */
    private function parsePrintR(string $body): array
    {
        $clean = strip_tags($body);
        $fields = [];
        if (preg_match_all('/^\s{4}\[([^\]]+)\]\s*=>\s*(?!Array)(.*)$/m', $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $fields[trim($row[1])] = trim($row[2]);
            }
        }
        return $fields;
    }
}
