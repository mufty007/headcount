<?php

declare(strict_types=1);

namespace Headcount\Services;

/**
 * Rule-based insights for admin reports (no ML).
 */
final class ReportInsightsBuilder
{
    private const MAX_INSIGHTS = 7;

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed>|null $prevStats
     * @param list<array<string, mixed>> $categoryData
     * @param list<array<string, mixed>> $eventPerformanceList
     * @param list<array<string, mixed>> $rsvpReportEvents
     * @param list<array<string, mixed>> $memberEngagementList
     * @param list<array<string, mixed>> $revenueByEventList
     * @param list<array{month?: string, new_count?: int, cumulative?: int}> $memberGrowthMonthly
     * @return list<array{id: string, severity: string, title: string, body: string, metric?: string, href?: string}>
     */
    public static function build(
        string $reportType,
        ReportFilterSet $filters,
        array $stats,
        ?array $prevStats,
        array $categoryData,
        int $noShowCount,
        float $noShowRate,
        array $revenueStats,
        array $eventPerformanceList,
        array $rsvpReportEvents,
        array $memberEngagementList,
        array $revenueByEventList,
        array $memberGrowthMonthly = [],
    ): array {
        $insights = [];
        $compare = $filters->compare && $prevStats !== null;
        $prevEndLabel = $filters->prevEndDate;

        if ($reportType === 'overview') {
            if ($compare) {
                self::addDelta($insights, 'ov_events', 'Events', (int) ($stats['total_events'] ?? 0), (int) ($prevStats['total_events'] ?? 0), $prevEndLabel);
                self::addDelta($insights, 'ov_att', 'Attendance check-ins', (int) ($stats['total_attendance'] ?? 0), (int) ($prevStats['total_attendance'] ?? 0), $prevEndLabel);
                self::addDelta($insights, 'ov_unique', 'Unique attendees', (int) ($stats['unique_attendees'] ?? 0), (int) ($prevStats['unique_attendees'] ?? 0), $prevEndLabel);
                self::addDelta($insights, 'ov_rsvp', 'RSVP yes count', (int) ($stats['total_rsvps'] ?? 0), (int) ($prevStats['total_rsvps'] ?? 0), $prevEndLabel);
            }
            if (($stats['total_rsvps'] ?? 0) > 0 && $noShowRate >= 25) {
                $insights[] = [
                    'id' => 'ov_noshow_high',
                    'severity' => 'danger',
                    'title' => 'High no-show rate',
                    'body' => sprintf('%.1f%% of RSVP yes responses did not check in. Consider reminders or waitlist policies.', $noShowRate),
                    'metric' => (string) $noShowCount . ' no-shows',
                ];
            } elseif (($stats['total_rsvps'] ?? 0) > 0 && $noShowRate >= 15) {
                $insights[] = [
                    'id' => 'ov_noshow_watch',
                    'severity' => 'warning',
                    'title' => 'No-shows worth watching',
                    'body' => sprintf('No-show rate is %.1f%% of RSVP yes—above a typical 15%% comfort zone.', $noShowRate),
                ];
            }
            if ($categoryData !== []) {
                usort($categoryData, static fn ($a, $b) => ((int) ($b['attendance_count'] ?? 0)) <=> ((int) ($a['attendance_count'] ?? 0)));
                $top = $categoryData[0];
                $totalAtt = (int) ($stats['total_attendance'] ?? 0);
                $topCount = (int) ($top['attendance_count'] ?? 0);
                if ($totalAtt > 0 && $topCount > 0) {
                    $pct = round(100 * $topCount / $totalAtt, 1);
                    if ($pct >= 35) {
                        $insights[] = [
                            'id' => 'ov_top_cat',
                            'severity' => 'info',
                            'title' => 'Top category by attendance',
                            'body' => sprintf('%s drives about %.1f%% of check-ins in this period.', (string) ($top['category'] ?? 'Uncategorized'), $pct),
                            'metric' => (string) $topCount . ' check-ins',
                        ];
                    }
                }
            }
            if (($stats['total_events'] ?? 0) === 0) {
                $insights[] = [
                    'id' => 'ov_empty',
                    'severity' => 'info',
                    'title' => 'No events in range',
                    'body' => 'Try widening the date range or clearing filters to see activity.',
                ];
            }
        } elseif ($reportType === 'events') {
            $list = self::filterEventRows($eventPerformanceList, $filters);
            if ($list === []) {
                $insights[] = [
                    'id' => 'ev_empty',
                    'severity' => 'info',
                    'title' => 'No matching events',
                    'body' => 'Adjust filters or the date range to see event performance.',
                ];
            } else {
                $sorted = $list;
                usort($sorted, static function ($a, $b) {
                    $ra = $a['rsvp_yes'] > 0 ? ($a['checked_in'] / max(1, $a['total_expected'])) : 0.0;
                    $rb = $b['rsvp_yes'] > 0 ? ($b['checked_in'] / max(1, $b['total_expected'])) : 0.0;

                    return $rb <=> $ra;
                });
                $best = $sorted[0] ?? null;
                if ($best && (int) ($best['total_expected'] ?? 0) > 0) {
                    $rate = round(100 * ((int) $best['checked_in']) / (int) $best['total_expected'], 1);
                    $insights[] = [
                        'id' => 'ev_best_conv',
                        'severity' => 'success',
                        'title' => 'Strongest check-in vs expected',
                        'body' => sprintf('%s is converting at about %.1f%% of expected headcount.', (string) $best['title'], $rate),
                    ];
                }
                $worstNs = $list;
                usort($worstNs, static fn ($a, $b) => ($b['no_show_pct'] ?? 0) <=> ($a['no_show_pct'] ?? 0));
                $w = $worstNs[0] ?? null;
                if ($w && ($w['no_show_pct'] ?? 0) >= 30 && ($w['rsvp_yes'] ?? 0) > 0) {
                    $insights[] = [
                        'id' => 'ev_worst_ns',
                        'severity' => 'danger',
                        'title' => 'Highest no-show event',
                        'body' => sprintf('%s has a %.1f%% no-show rate among primary RSVP yes.', (string) $w['title'], (float) $w['no_show_pct']),
                    ];
                }
                $highUtil = array_values(array_filter($list, static fn ($r) => ($r['utilization_pct'] ?? null) !== null && (float) $r['utilization_pct'] >= 90));
                if (count($highUtil) >= 2) {
                    $insights[] = [
                        'id' => 'ev_util',
                        'severity' => 'info',
                        'title' => 'High utilization',
                        'body' => sprintf('%d events used at least 90%% of listed capacity—consider waitlists or overflow.', count($highUtil)),
                    ];
                }
            }
        } elseif ($reportType === 'rsvp') {
            $list = self::filterEventRows($rsvpReportEvents, $filters);
            $totalNs = array_sum(array_map(static fn ($r) => (int) ($r['no_show_count'] ?? 0), $list));
            $totalRsvp = array_sum(array_map(static fn ($r) => (int) ($r['rsvp_yes'] ?? 0), $list));
            if ($totalRsvp > 0) {
                $conv = round(100 * (array_sum(array_map(static fn ($r) => (int) ($r['checked_in'] ?? 0), $list)) / max(1, $totalRsvp)), 1);
                $insights[] = [
                    'id' => 'rsvp_conv',
                    'severity' => $conv >= 75 ? 'success' : ($conv >= 50 ? 'info' : 'warning'),
                    'title' => 'RSVP to check-in (primary yes)',
                    'body' => sprintf('Across filtered events, check-ins are about %.1f%% of primary RSVP yes count.', $conv),
                ];
            }
            if ($list !== [] && $totalNs > 0) {
                usort($list, static fn ($a, $b) => ((int) ($b['no_show_count'] ?? 0)) <=> ((int) ($a['no_show_count'] ?? 0)));
                $pareto = 0;
                $target = (int) round(0.8 * $totalNs);
                foreach ($list as $row) {
                    $pareto += (int) ($row['no_show_count'] ?? 0);
                    if ($pareto >= $target) {
                        $insights[] = [
                            'id' => 'rsvp_pareto',
                            'severity' => 'warning',
                            'title' => 'No-show concentration',
                            'body' => sprintf('A small set of events drives most no-shows; top contributor: %s (%d).', (string) ($list[0]['title'] ?? ''), (int) ($list[0]['no_show_count'] ?? 0)),
                        ];
                        break;
                    }
                }
            }
        } elseif ($reportType === 'members') {
            $newTotal = 0;
            $peakMonth = null;
            $peakCount = -1;
            foreach ($memberGrowthMonthly as $row) {
                $c = (int) ($row['new_count'] ?? 0);
                $newTotal += $c;
                if ($c > $peakCount) {
                    $peakCount = $c;
                    $peakMonth = (string) ($row['month'] ?? '');
                }
            }
            if ($memberGrowthMonthly !== []) {
                $ending = (int) ($memberGrowthMonthly[array_key_last($memberGrowthMonthly)]['cumulative'] ?? 0);
                $insights[] = [
                    'id' => 'mem_growth_total',
                    'severity' => 'info',
                    'title' => 'New members in range',
                    'body' => sprintf(
                        '%d new active member%s joined in this period. Cumulative active members ended at %d.',
                        $newTotal,
                        $newTotal === 1 ? '' : 's',
                        $ending
                    ),
                ];
                if ($peakMonth !== null && $peakCount > 0) {
                    $insights[] = [
                        'id' => 'mem_growth_peak',
                        'severity' => 'info',
                        'title' => 'Strongest signup month',
                        'body' => sprintf('%s had the most new members (%d).', $peakMonth, $peakCount),
                    ];
                }
                if (count($memberGrowthMonthly) >= 2) {
                    $last = (int) ($memberGrowthMonthly[array_key_last($memberGrowthMonthly)]['new_count'] ?? 0);
                    $prevIdx = array_key_last($memberGrowthMonthly) - 1;
                    $prev = (int) ($memberGrowthMonthly[$prevIdx]['new_count'] ?? 0);
                    if ($prev > 0 || $last > 0) {
                        $delta = $last - $prev;
                        $dir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
                        $insights[] = [
                            'id' => 'mem_growth_mom',
                            'severity' => $delta < 0 ? 'warning' : 'info',
                            'title' => 'Month-over-month signups',
                            'body' => $dir === 'flat'
                                ? sprintf('Latest month matched the prior month (%d new).', $last)
                                : sprintf(
                                    'Latest month is %s by %d vs the prior month (%d → %d).',
                                    $dir,
                                    abs($delta),
                                    $prev,
                                    $last
                                ),
                        ];
                    }
                }
            }

            $n = count($memberEngagementList);
            if ($n === 0) {
                $insights[] = [
                    'id' => 'mem_empty',
                    'severity' => 'info',
                    'title' => 'No engagement in range',
                    'body' => 'No members had RSVP or attendance tied to filtered events in this period.',
                ];
            } else {
                $rates = array_map(static fn ($m) => (float) ($m['attendance_rate'] ?? 0), $memberEngagementList);
                sort($rates);
                $mid = (int) floor(count($rates) / 2);
                $median = $rates[$mid] ?? 0;
                $insights[] = [
                    'id' => 'mem_median',
                    'severity' => 'info',
                    'title' => 'Attendance vs RSVP (members shown)',
                    'body' => sprintf('Median attendance rate is %.1f%% across %d members with activity.', $median, $n),
                ];
                $highNs = count(array_filter($memberEngagementList, static fn ($m) => (int) ($m['no_shows'] ?? 0) >= 3));
                if ($highNs > 0) {
                    $insights[] = [
                        'id' => 'mem_high_ns',
                        'severity' => 'warning',
                        'title' => 'Repeat no-shows',
                        'body' => sprintf('%d members have 3+ no-shows in this period—consider follow-up.', $highNs),
                    ];
                }
            }
        } elseif ($reportType === 'revenue') {
            $total = (float) ($revenueStats['total_revenue'] ?? 0);
            if ($total <= 0) {
                $insights[] = [
                    'id' => 'rev_empty',
                    'severity' => 'info',
                    'title' => 'No paid revenue',
                    'body' => 'No paid check-ins in this range, or filters excluded all paid activity.',
                ];
            } else {
                $sorted = $revenueByEventList;
                usort($sorted, static fn ($a, $b) => ((float) ($b['revenue'] ?? 0)) <=> ((float) ($a['revenue'] ?? 0)));
                $top3 = array_slice($sorted, 0, 3);
                $topSum = array_sum(array_map(static fn ($r) => (float) ($r['revenue'] ?? 0), $top3));
                if ($total > 0) {
                    $conc = round(100 * $topSum / $total, 1);
                    $insights[] = [
                        'id' => 'rev_conc',
                        'severity' => $conc > 70 ? 'warning' : 'info',
                        'title' => 'Revenue concentration',
                        'body' => sprintf('Top 3 events represent about %.1f%% of paid revenue in this period.', $conc),
                    ];
                }
                $paid = (int) ($revenueStats['paid_count'] ?? 0);
                if ($paid > 0) {
                    $avg = $total / $paid;
                    $insights[] = [
                        'id' => 'rev_avg',
                        'severity' => 'success',
                        'title' => 'Average per paid registration',
                        'body' => sprintf('About $%s per paid registration in the selected range.', number_format($avg, 2)),
                    ];
                }
            }
        }

        usort($insights, static function ($a, $b) {
            $order = ['danger' => 0, 'warning' => 1, 'success' => 2, 'info' => 3];

            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return array_slice($insights, 0, self::MAX_INSIGHTS);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function filterEventRows(array $rows, ReportFilterSet $filters): array
    {
        $out = [];
        foreach ($rows as $r) {
            $rsvp = (int) ($r['rsvp_yes'] ?? 0);
            $ns = isset($r['no_show_pct']) ? (float) $r['no_show_pct'] : ($rsvp > 0
                ? round(100 * ((int) ($r['no_show_count'] ?? 0)) / $rsvp, 1)
                : 0.0);
            if ($filters->minRsvpYes !== null && $rsvp < $filters->minRsvpYes) {
                continue;
            }
            if ($filters->minNoShowPct !== null && $ns < $filters->minNoShowPct) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $insights
     */
    private static function addDelta(array &$insights, string $id, string $label, int $cur, int $prev, string $priorPeriodEnd): void
    {
        if ($prev <= 0) {
            return;
        }
        $diff = $cur - $prev;
        $pct = round(100 * $diff / $prev, 1);
        $sev = $diff >= 0 ? 'success' : 'warning';
        if ($label === 'No-shows' || str_contains(strtolower($label), 'no-show')) {
            $sev = $diff <= 0 ? 'success' : 'warning';
        }
        $insights[] = [
            'id' => $id,
            'severity' => $sev,
            'title' => $label . ' vs prior period',
            'body' => sprintf('%+d (%+.1f%%) vs the prior window ending %s.', $diff, $pct, $priorPeriodEnd),
            'metric' => (string) $cur,
        ];
    }
}
