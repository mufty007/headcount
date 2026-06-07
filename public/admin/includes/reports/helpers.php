<?php

declare(strict_types=1);

use Headcount\Services\ReportFilterSet;

/**
 * @param array<string, mixed> $extra
 */
if (!function_exists('hc_reports_url')) {
    function hc_reports_url(string $routerBase, array $extra): string
    {
        $base = rtrim($routerBase, '/');
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . http_build_query($extra);
    }
}

/**
 * Echo hidden inputs to preserve filters in a nested form (e.g. date range).
 *
 * @param array<int, string> $skip keys to skip from toQueryParams keys
 */
if (!function_exists('hc_reports_filter_hidden_inputs')) {
    function hc_reports_filter_hidden_inputs(ReportFilterSet $filters, array $skip = []): void
    {
        foreach ($filters->toQueryParams() as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            if ($k === 'categories' && is_array($v)) {
                foreach ($v as $c) {
                    echo '<input type="hidden" name="categories[]" value="' . e((string) $c) . '">';
                }
            } elseif (!is_array($v)) {
                echo '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
            }
        }
    }
}
