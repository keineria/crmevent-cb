<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable starting point for a campaign. A campaign copies its content, so editing a template never changes what was sent.
 */
#[Fillable(['name', 'subject', 'preheader', 'body', 'updated_by'])]
class MailTemplate extends Model {}
