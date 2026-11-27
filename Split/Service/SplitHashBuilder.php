<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

/**
 * Builds the PayU postservice authentication hash. One formula serves every split
 * command: sha512(key|command|var1|salt). The third field (var1) is the split JSON
 * for payment_split, or the payuId for get_split_info / release_settlement. Mirrors
 * the formula proven live in tools/payu-lab (docs/payu/kb/split-api.md §11).
 *
 * Pure: no dependencies, no I/O. A wrong field order or delimiter yields AGG-300
 * (hash validation failed) from PayU, so the order here is contractual.
 */
class SplitHashBuilder
{
    /**
     * @param string $merchantKey Parent (aggregator) merchant key
     * @param string $command PayU command, e.g. payment_split / get_split_info / release_settlement
     * @param string $var1 The command's var1 value (split JSON, or the payuId)
     * @param string $salt Parent merchant salt (used raw — the PayU salt field is not encrypted)
     * @return string Lowercase hex SHA-512 hash
     */
    public function postservice(string $merchantKey, string $command, string $var1, string $salt): string
    {
        return hash('sha512', $merchantKey . '|' . $command . '|' . $var1 . '|' . $salt);
    }
}
