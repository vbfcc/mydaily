<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CbtRecord extends Model
{
    protected $table = 'cbt_records';

    protected $fillable = [
        'chat_id',
        'platform',
        'date_shamsi',
        'event',
        'thought',
        'distortion',
        'distortions',
        'feeling',
        'score_thought',
        'score_feeling',
        'evidence_for',
        'evidence_against',
        'alternative_reasons',
        'others_agree',
        'pros_cons',
        'testable',
        'best_friend',
        'closing_answers',
        'reaction',
        'score_thought_after',
        'score_feeling_after',
    ];

    protected $casts = [
        'distortions' => 'array',
        'closing_answers' => 'array',
    ];

    /**
     * For JSON export — match previous file-based readRecords shape.
     */
    public function toExportArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date_shamsi ?? '-',
            'event' => $this->event ?? '-',
            'thought' => $this->thought ?? '-',
            'distortion' => $this->distortion ?? '-',
            'distortions' => $this->distortions ?? [],
            'feeling' => $this->feeling ?? '-',
            'score_thought' => $this->score_thought ?? '-',
            'score_feeling' => $this->score_feeling ?? '-',
            'score_thought_after' => $this->score_thought_after ?? '-',
            'score_feeling_after' => $this->score_feeling_after ?? '-',
            'reaction' => $this->reaction ?? '-',
            'evidence_for' => $this->evidence_for ?? '-',
            'evidence_against' => $this->evidence_against ?? '-',
            'alternative_reasons' => $this->alternative_reasons ?? '-',
            'others_agree' => $this->others_agree ?? '-',
            'pros_cons' => $this->pros_cons ?? '-',
            'testable' => $this->testable ?? '-',
            'best_friend' => $this->best_friend ?? '-',
            'closing_answers' => $this->closing_answers ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_shamsi' => $this->created_at ? \App\Helpers\ShamsiDateHelper::fullDateTime($this->created_at) : null,
        ];
    }
}
