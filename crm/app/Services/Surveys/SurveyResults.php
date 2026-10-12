<?php

namespace App\Services\Surveys;

use App\Models\Survey;
use App\Models\SurveyInvitation;
use Illuminate\Support\Collection;

/**
 * What people answered: per question of one survey, and the headline numbers of every survey of an edition.
 *
 * Satisfaction is the average of all 1–5 answers; NPS is % of 9–10 minus % of 0–6 on the 0–10 questions.
 */
class SurveyResults
{
    /**
     * @return array{invited: int, sent: int, responded: int, rate: float|null, satisfaction: float|null, nps: int|null}
     */
    public function totals(Survey $survey, ?Collection $invitations = null): array
    {
        $invitations ??= $survey->invitations()->get(['id', 'sent_at', 'responded_at', 'answers']);
        $answered = $invitations->whereNotNull('responded_at');

        return [
            'invited' => $invitations->count(),
            'sent' => $invitations->whereNotNull('sent_at')->count(),
            'responded' => $answered->count(),
            'rate' => self::rate($answered->count(), $invitations->whereNotNull('sent_at')->count()),
            'satisfaction' => self::average($this->values($survey, 'rating', $invitations)),
            'nps' => self::nps($this->values($survey, 'nps', $invitations)),
        ];
    }

    /**
     * Every question with what was answered to it.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(Survey $survey): array
    {
        $answered = $survey->invitations()->whereNotNull('responded_at')->orderByDesc('responded_at')->get(['id', 'responded_at', 'answers']);

        return collect($survey->questions)->map(function (array $q) use ($answered) {
            $values = $answered->map(fn (SurveyInvitation $i) => $i->answers[$q['key']] ?? null)->filter(fn ($v) => $v !== null && $v !== '');
            $base = ['key' => $q['key'], 'type' => $q['type'], 'label' => $q['label'], 'answers' => $values->count()];

            return $base + match ($q['type']) {
                'rating' => [
                    'average' => self::average($values),
                    'counts' => collect(range(1, 5))->map(fn ($n) => ['label' => (string) $n, 'count' => $values->filter(fn ($v) => $v === $n)->count()])->all(),
                ],
                'nps' => [
                    'nps' => self::nps($values),
                    'counts' => [
                        ['label' => 'Promotores (9–10)', 'count' => $values->filter(fn ($v) => $v >= 9)->count()],
                        ['label' => 'Neutros (7–8)', 'count' => $values->filter(fn ($v) => $v >= 7 && $v <= 8)->count()],
                        ['label' => 'Detractores (0–6)', 'count' => $values->filter(fn ($v) => $v <= 6)->count()],
                    ],
                ],
                'choice' => [
                    'counts' => collect($q['options'] ?? [])->map(fn ($o) => ['label' => $o, 'count' => $values->filter(fn ($v) => $v === $o)->count()])->all(),
                ],
                default => [
                    // Comments carry no name: the survey tells people their answers are used only to improve the fair.
                    'comments' => $answered->filter(fn (SurveyInvitation $i) => filled($i->answers[$q['key']] ?? null))
                        ->map(fn (SurveyInvitation $i) => ['text' => $i->answers[$q['key']], 'at' => $i->responded_at->toIso8601String()])
                        ->values()->all(),
                ],
            };
        })->all();
    }

    /**
     * Each survey of the edition (by activity date) with its headline numbers, and the totals of all of them.
     *
     * @return array{surveys: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function edition(int $year): array
    {
        $surveys = Survey::whereYear('activity_date', $year)->orderBy('activity_date')->orderBy('activity_name')->get();
        $invitations = SurveyInvitation::whereIn('survey_id', $surveys->pluck('id'))->get(['id', 'survey_id', 'sent_at', 'responded_at', 'answers'])
            ->groupBy('survey_id');

        $rows = $surveys->map(fn (Survey $s) => [
            'id' => $s->id,
            'activity_name' => $s->activity_name,
            'activity_type' => $s->activity_type,
            'activity_date' => $s->activity_date?->toDateString(),
            ...$this->totals($s, $invitations->get($s->id, collect())),
        ]);

        $sent = $rows->sum('sent');
        $responded = $rows->sum('responded');
        $all = fn (string $type) => $surveys->flatMap(fn (Survey $s) => $this->values($s, $type, $invitations->get($s->id, collect())));

        return [
            'surveys' => $rows->values()->all(),
            'totals' => [
                'surveys' => $surveys->count(),
                'sent' => $sent,
                'responded' => $responded,
                'rate' => self::rate($responded, $sent),
                'satisfaction' => self::average($all('rating')),
                'nps' => self::nps($all('nps')),
            ],
        ];
    }

    /**
     * @param  Collection<int, SurveyInvitation>  $invitations
     * @return Collection<int, int>
     */
    private function values(Survey $survey, string $type, Collection $invitations): Collection
    {
        $keys = collect($survey->questions)->where('type', $type)->pluck('key');

        return $invitations->whereNotNull('responded_at')
            ->flatMap(fn (SurveyInvitation $i) => $keys->map(fn ($k) => $i->answers[$k] ?? null))
            ->filter(fn ($v) => is_int($v))->values();
    }

    public static function average(Collection $values): ?float
    {
        return $values->isEmpty() ? null : round($values->avg(), 1);
    }

    public static function nps(Collection $values): ?int
    {
        if ($values->isEmpty()) {
            return null;
        }

        $promoters = $values->filter(fn ($v) => $v >= 9)->count();
        $detractors = $values->filter(fn ($v) => $v <= 6)->count();

        return (int) round(($promoters - $detractors) * 100 / $values->count());
    }

    public static function rate(int $part, int $of): ?float
    {
        return $of ? round($part * 100 / $of, 1) : null;
    }
}
