<?php

namespace App\Domain\Ai;

final class EvidenceVerifier
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $map
     * @return array<string, mixed> */
    public function verify(array $payload, string $text, int $documentId, array $map = []): array
    {
        return $this->walk($payload, $text, $documentId, $map);
    }

    /**
     * @param array<mixed> $node
     * @param array<string, string> $map
     * @return array<mixed> */
    private function walk(array $node, string $text, int $documentId, array $map): array
    {
        if (array_key_exists('value', $node) && isset($node['evidence'], $node['confidence'])) {
            if (is_string($node['value'])) {
                $node['value'] = strtr($node['value'], $map);
            }
            if ($node['value'] === null && $node['evidence'] === []) {
                return $node + ['source' => 'model'];
            }
            if ($node['value'] === null || $node['evidence'] === []) {
                throw new AiException(AiError::InvalidSchema);
            }
            $verified = [];
            $error = null;
            foreach ($node['evidence'] as $item) {
                $quote = strtr($item['quote'], $map);
                if ($quote === '' || (isset($item['page']) && $item['page'] > substr_count($text, "\f") + 1)) {
                    throw new AiException(AiError::InvalidSchema);
                }
                $positions = [];
                $cursor = 0;
                while (($start = mb_strpos($text, $quote, $cursor, 'UTF-8')) !== false) {
                    $page = substr_count(mb_substr($text, 0, $start, 'UTF-8'), "\f") + 1;
                    if (($item['page'] ?? null) === null || (int) $item['page'] === $page) {
                        $positions[] = [$start, $page];
                    }
                    $cursor = $start + 1;
                }
                if (count($positions) !== 1) {
                    $error = $positions === [] ? 'quote_not_found' : 'quote_ambiguous';

                    continue;
                }
                [$start, $page] = $positions[0];
                $end = $start + mb_strlen($quote, 'UTF-8');
                if ($end <= $start) {
                    throw new AiException(AiError::InvalidSchema);
                }
                $verified[] = ['quote' => $quote, 'document_id' => $documentId, 'page' => $page, 'start' => $start, 'end' => $end];
            }
            $node['evidence'] = $verified;
            $node['source'] = 'model';
            if ($error !== null) {
                $node['confidence'] = 0;
                $node['verification_error'] = $error;
            }

            return $node;
        }
        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = $this->walk($child, $text, $documentId, $map);
            }
        }

        return $node;
    }
}
