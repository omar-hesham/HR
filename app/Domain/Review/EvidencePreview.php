<?php

namespace App\Domain\Review;

use Illuminate\Support\HtmlString;

final class EvidencePreview
{
    /** Coordinates are Unicode code points, end exclusive. Invalid quotes never highlight unrelated text.
     * @param list<array<string,mixed>> $evidence */
    public function render(string $text, array $evidence, int $documentId): HtmlString
    {
        $ranges = [];
        $boundaries = [0, mb_strlen($text)];
        $anchors = [];
        foreach ($evidence as $item) {
            $start = $item['start'] ?? null;
            $end = $item['end'] ?? null;
            if (($item['document_id'] ?? null) !== $documentId || ! is_int($start) || ! is_int($end)
                || $start < 0 || $end <= $start || $end > mb_strlen($text)
                || mb_substr($text, $start, $end - $start) !== ($item['quote'] ?? null)) {
                continue;
            }
            $ranges[] = [$start, $end];
            $boundaries[] = $start;
            $boundaries[] = $end;
            $anchors[$start] = true;
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);
        $html = '';
        for ($index = 0; $index + 1 < count($boundaries); $index++) {
            $start = $boundaries[$index];
            $end = $boundaries[$index + 1];
            $fragment = e(mb_substr($text, $start, $end - $start));
            $highlighted = false;
            foreach ($ranges as [$rangeStart, $rangeEnd]) {
                if ($start >= $rangeStart && $end <= $rangeEnd) {
                    $highlighted = true;
                    break;
                }
            }
            $anchor = isset($anchors[$start]) ? ' id="evidence-'.$documentId.'-'.$start.'"' : '';
            $html .= $highlighted ? '<mark'.$anchor.'>'.$fragment.'</mark>' : $fragment;
        }

        return new HtmlString($html);
    }
}
