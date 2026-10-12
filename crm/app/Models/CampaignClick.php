<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campaign_recipient_id', 'url', 'created_at'])]
class CampaignClick extends Model
{
    public const UPDATED_AT = null;
}
