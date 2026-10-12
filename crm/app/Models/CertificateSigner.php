<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Someone who signs certificates: name, role and an image of the signature. Kept once, picked in each category.
 */
#[Fillable(['name', 'role', 'image_path', 'created_by'])]
class CertificateSigner extends Model
{
    protected static function booted(): void
    {
        static::deleted(function (self $signer) {
            if ($signer->image_path) {
                Storage::disk('local')->delete($signer->image_path);
            }
        });
    }

    /**
     * Categories that print this signer.
     *
     * @return Collection<int, CertificateCategory>
     */
    public function categories(): Collection
    {
        return CertificateCategory::all()->filter(fn (CertificateCategory $c) => in_array($this->id, $c->signer_ids ?? [], true))->values();
    }
}
