<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\MailTemplate;
use App\Services\Campaigns\CampaignRenderer;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('campaigns/templates', [
            'canEditQrMail' => $request->user()->hasAnyRole(Roles::QR_MAIL_EDITORS),
            'templates' => MailTemplate::forCampaigns()->orderBy('name')->get()->map(fn (MailTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'subject' => $t->subject,
                'preheader' => $t->preheader,
                'updated_at' => $t->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['from_campaign' => ['nullable', 'integer', 'exists:campaigns,id']]);
        $source = isset($data['from_campaign']) ? Campaign::find($data['from_campaign']) : null;

        $template = MailTemplate::create([
            'name' => $source ? $source->name : 'Nueva plantilla',
            'subject' => $source->subject ?? '',
            'preheader' => $source?->preheader,
            'body' => $source->body ?? "# Hola {{nombre|amigo}}\n\nEscribe aquí tu mensaje.",
            'updated_by' => $request->user()->id,
        ]);

        return to_route('campaigns.templates.edit', $template)->with('success', $source ? 'Guardada como plantilla.' : null);
    }

    public function edit(MailTemplate $template): Response
    {
        abort_if($template->system_key !== null, 404);

        return Inertia::render('campaigns/template-edit', [
            'template' => $template->only(['id', 'name', 'subject', 'preheader', 'body']),
            'fields' => CampaignRenderer::FIELDS,
        ]);
    }

    public function update(Request $request, MailTemplate $template): RedirectResponse
    {
        abort_if($template->system_key !== null, 404);

        $data = $request->validate(CampaignController::contentRules());

        $template->update([
            'name' => $data['name'],
            'subject' => $data['subject'] ?? '',
            'preheader' => $data['preheader'] ?? null,
            'body' => $data['body'] ?? '',
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Plantilla guardada.');
    }

    public function destroy(MailTemplate $template): RedirectResponse
    {
        abort_if($template->system_key !== null, 404);

        $template->delete();

        return to_route('campaigns.templates.index')->with('success', 'Plantilla eliminada.');
    }
}
