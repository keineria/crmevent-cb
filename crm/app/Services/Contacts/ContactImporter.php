<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\ContactImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates or completes contacts from an uploaded spreadsheet using the column mapping the user chose.
 *
 * People are matched by document number first, then by email, then (without either) by phone and first name
 * or by full name.
 * Families often register under one email or phone, so a shared email never merges two people with
 * different documents: the second one is saved without email. Saved values are never overwritten, and a
 * contact who unsubscribed stays unsubscribed even if the file says otherwise.
 */
class ContactImporter
{
    private const PROGRESS_EVERY = 100;

    public function __construct(private SpreadsheetReader $reader, private ContactMatcher $matcher) {}

    public function run(ContactImport $import): void
    {
        $import->update(['status' => ContactImport::STATUS_PROCESSING]);

        $counts = ['created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0];
        $errors = [];
        $mapping = array_filter($import->mapping ?? []);
        $headers = $import->headers ?? [];
        $path = Storage::disk('local')->path($import->path);

        foreach ($this->reader->rows($path, pathinfo($import->path, PATHINFO_EXTENSION)) as $index => $row) {
            if ($index === 0 || $this->isBlank($row) || ImportFields::isHeaderRow($row, $headers)) {
                continue;
            }

            $values = [];
            $details = [];
            foreach ($mapping as $column => $field) {
                $value = trim((string) ($row[(int) $column] ?? ''));
                if ($field === ImportFields::DETAIL) {
                    if ($value !== '') {
                        $details[$headers[(int) $column] ?: 'Columna '.((int) $column + 1)] = Str::limit($value, 500, '');
                    }
                } else {
                    $values[$field] = $value;
                }
            }

            $messages = [];
            $outcome = DB::transaction(function () use ($import, $values, $details, &$messages) {
                return $this->importRow($import, $values, $details, $messages);
            });
            $counts[$outcome.'_count']++;

            foreach ($messages as $message) {
                if (count($errors) < ContactImport::MAX_ERRORS) {
                    $errors[] = ['row' => $index + 1, 'message' => $message];
                }
            }

            if ($index % self::PROGRESS_EVERY === 0) {
                $import->update($counts);
            }
        }

        $import->update($counts + [
            'errors' => $errors ?: null,
            'status' => ContactImport::STATUS_DONE,
            'finished_at' => now(),
        ]);

        // The data now lives in the contacts table; the uploaded file holds personal data we no longer need.
        Storage::disk('local')->delete($import->path);
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $details
     * @param  list<string>  $messages  problems to show in the import report
     * @return 'created'|'updated'|'skipped'
     */
    private function importRow(ContactImport $import, array $values, array $details, array &$messages): string
    {
        $data = $this->clean($values);

        if (($data['email'] ?? null) === false) {
            $messages[] = "Correo no válido: «{$values['email']}». Se guardó sin correo.";
            unset($data['email']);
        }

        if (empty($data['email']) && empty($data['document_number']) && empty($data['phone'])
            && (empty($data['first_name']) || empty($data['last_name']))) {
            $messages[] = 'Sin correo, documento, teléfono ni nombre completo: no se puede identificar a la persona.';

            return 'skipped';
        }

        $contact = $this->matcher->find($data);

        // The email belongs to someone else (a relative or a colleague who registered them): keep it with its owner.
        if (! empty($data['email']) && $contact?->email !== $data['email'] && Contact::where('email', $data['email'])->exists()) {
            $who = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: 'Esta persona';
            $messages[] = "{$who} comparte el correo {$data['email']} con otra persona; se guardó sin correo.";
            unset($data['email']);
        }

        $isNew = $contact === null;
        $contact ??= new Contact(['source' => Contact::SOURCE_IMPORT]);

        foreach ([...Contact::PROFILE_FIELDS, 'email'] as $field) {
            if (! empty($data[$field]) && blank($contact->{$field})) {
                $contact->{$field} = $data[$field];
            }
        }

        $role = $import->kind;
        $contact->{$role === ContactImport::KIND_ATTENDEE ? 'is_attendee' : 'is_interested'} = true;

        if ($import->has_consent && $contact->unsubscribed_at === null && ! $contact->marketing_consent && $contact->email) {
            $contact->grantConsent("Importación «{$import->original_name}»");
        }

        $dirty = $contact->isDirty();
        $contact->save();

        $year = $data['edition_year'] ?? $import->edition_year;
        $editionChanged = false;
        if ($year) {
            $edition = ContactEdition::firstOrNew(
                ['contact_id' => $contact->id, 'year' => $year, 'role' => $role],
                ['contact_import_id' => $import->id],
            );
            if ($details) {
                $edition->details = array_merge($edition->details ?? [], $details);
            }
            $editionChanged = ! $edition->exists || $edition->isDirty();
            $edition->save();
        }

        return match (true) {
            $isNew => 'created',
            $dirty || $editionChanged => 'updated',
            default => 'skipped',
        };
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string|int|false> email is false when present but invalid
     */
    private function clean(array $values): array
    {
        $data = array_map(fn ($v) => trim(preg_replace('/\s+/u', ' ', (string) $v)), $values);
        $data = array_filter($data, fn ($v) => $v !== '' && $v !== '-' && ! in_array(Str::lower($v), ['n/a', 'na', 'no aplica', 'null'], true));

        if (isset($data['full_name']) && empty($data['first_name']) && empty($data['last_name'])) {
            [$data['first_name'], $data['last_name']] = $this->splitName($data['full_name']);
        }
        unset($data['full_name']);

        foreach (['first_name', 'last_name', 'city', 'state', 'gender'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Str::title(Str::lower($data[$field]));
            }
        }

        if (isset($data['email'])) {
            $email = Str::lower(str_replace(' ', '', $data['email']));
            $data['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
        }

        if (isset($data['document_number'])) {
            $data['document_number'] = Str::upper(preg_replace('/[\s.\-]/', '', $data['document_number']));
        }

        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/[^\d+]/', '', $data['phone']) ?: null;
        }

        if (isset($data['edition_year'])) {
            $year = (int) preg_replace('/\D/', '', $data['edition_year']);
            $data['edition_year'] = $year >= 2000 && $year <= 2100 ? $year : null;
        }

        foreach (Contact::PROFILE_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = Str::limit($data[$field], match ($field) {
                    'notes' => 2000,
                    'document_type', 'gender' => 30,
                    'phone', 'document_number' => 40,
                    default => 120,
                }, '');
            }
        }

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Spanish names usually carry two surnames: "Ana María Pérez Gómez" → ["Ana María", "Pérez Gómez"].
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $parts = explode(' ', $name);

        return match (count($parts)) {
            1 => [$parts[0], null],
            2 => [$parts[0], $parts[1]],
            3 => [$parts[0], "{$parts[1]} {$parts[2]}"],
            default => [implode(' ', array_slice($parts, 0, -2)), implode(' ', array_slice($parts, -2))],
        };
    }

    /**
     * @param  list<string>  $row
     */
    private function isBlank(array $row): bool
    {
        return implode('', $row) === '';
    }
}
