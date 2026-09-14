<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One score, kept.
 *
 * Every field the results page needs to filter or count is a column; the whole
 * payload is kept alongside so nothing is lost to a schema that turned out to
 * be too narrow later.
 */
class SlidePrediction extends Model
{
    protected $fillable = [
        'sample_id', 'model_key', 'model_label',
        'call', 'p_ilc', 'decision', 'referred', 'ood_status', 'familiarity',
        'truth', 'site', 'patches', 'payload',
    ];

    protected $casts = [
        'payload'     => 'array',
        'referred'    => 'boolean',
        'p_ilc'       => 'float',
        'familiarity' => 'float',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    /** Was the raw lean right, where a truth is known? Null when it is not. */
    public function isCorrect(): ?bool
    {
        if (! $this->truth || ! $this->call) {
            return null;
        }
        return strcasecmp($this->truth, $this->call) === 0;
    }

    /** Did a guard stop this answer reaching anyone? */
    public function wasWithheld(): bool
    {
        return $this->decision === 'DO NOT USE';
    }

    /**
     * Record a result, and the truth as it stands right now.
     *
     * The truth is copied rather than joined so a later relabelling cannot
     * quietly change how an old run appears to have scored.
     */
    public static function record(Sample $sample, array $result, string $modelKey): self
    {
        $barcode = (string) ($sample->entity_submitter_id ?: '');
        $parts = explode('-', $barcode);

        return self::create([
            'sample_id'   => $sample->id,
            'model_key'   => $modelKey,
            'model_label' => $result['model_label'] ?? null,
            'call'        => $result['call'] ?? null,
            'p_ilc'       => $result['p_ilc'] ?? null,
            'decision'    => $result['decision'] ?? null,
            'referred'    => (bool) ($result['referred'] ?? false),
            'ood_status'  => $result['ood_status'] ?? null,
            'familiarity' => $result['familiarity'] ?? null,
            'truth'       => $sample->diseaseSubtype?->name,
            'site'        => count($parts) >= 2 ? $parts[1] : null,
            'patches'     => $result['patches'] ?? null,
            'payload'     => $result,
        ]);
    }
}
