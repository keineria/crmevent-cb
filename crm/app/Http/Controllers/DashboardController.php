<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\EventActivity;
use App\Models\Registration;
use App\Models\RegistrationParticipant;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        // Desk-only staff (e.g. Registro salidas) have nothing to read here; send them to their tool.
        if (! $request->user()->hasAnyRole([...Roles::REGISTRATION_VIEWERS, ...Roles::CONTACT_VIEWERS])
            && $request->user()->hasAnyRole(Roles::CHECKIN_STAFF)) {
            return redirect()->route('checkin.index');
        }

        $paid = fn () => Registration::whereIn('status', Registration::PAID_STATUSES);

        // Distinct attendees per fair, and how many of them had come to an earlier one.
        $years = ContactEdition::query()
            ->where('role', ContactEdition::ROLE_ATTENDEE)
            ->select('year', DB::raw('COUNT(DISTINCT contact_id) as attendees'))
            ->groupBy('year')
            ->orderByDesc('year')
            ->get()
            ->map(fn ($row) => [
                'year' => (int) $row->year,
                'attendees' => (int) $row->attendees,
                'returning' => ContactEdition::query()
                    ->where('role', ContactEdition::ROLE_ATTENDEE)
                    ->where('year', $row->year)
                    ->whereExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('contact_editions as earlier')
                        ->whereColumn('earlier.contact_id', 'contact_editions.contact_id')
                        ->where('earlier.role', ContactEdition::ROLE_ATTENDEE)
                        ->where('earlier.year', '<', $row->year))
                    ->distinct()
                    ->count('contact_id'),
            ]);

        return Inertia::render('dashboard', [
            'contacts' => [
                'total' => Contact::count(),
                'mailable' => Contact::mailable()->count(),
                'subscribers' => Contact::where('is_subscriber', true)->count(),
                'loyal' => ContactEdition::where('role', ContactEdition::ROLE_ATTENDEE)
                    ->select('contact_id')->groupBy('contact_id')->havingRaw('COUNT(DISTINCT year) >= 3')->get()->count(),
            ],
            'years' => $years,
            'plugin' => [
                'paid_orders' => $paid()->count(),
                'revenue' => (float) $paid()->sum('total'),
                'participants' => RegistrationParticipant::whereHas('registration', fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES))->count(),
            ],
            'fullest' => EventActivity::where('status', 'active')->where('capacity_total', '>', 0)
                ->orderByRaw('(confirmed_count + reserved_count) * 1.0 / capacity_total DESC')
                ->limit(5)
                ->get(['evr_id', 'name', 'type', 'event_date', 'capacity_total', 'confirmed_count', 'reserved_count']),
        ]);
    }
}
