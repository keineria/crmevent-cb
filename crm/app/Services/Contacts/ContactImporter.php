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
 * Existing contacts are matched by email, then by document number, then (rows without email) by phone. Saved values are never
 * overwritten, and a contact who unsubscribed stays unsubscribed even if the file says otherwise.
 */
class ContactImporter
{
    private const PROGRESS_EVERY = 100;

    public function __construct(private SpreadsheetReader $reader) {}

    public function run(ContactImport $import): void
    {
        $import->update(['status' => ContactImport::STATUS_PROCESSING]);

        $counts = ['created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0];
        $errors = [];
        $mapping = array_filter($import->mapping ?? []);
        $path = Storage::disk('local')->path($import->path);

        foreach ($this->reader->rows($path, pathinfo($import->path, PATHINFO_EXTENSION)) as $index => $row) {
            if ($index === 0 || $this->isBlank($row)) {
                continue;
            }

            $values = [];
            foreach ($mapping as $column => $field) {
                $values[$field] = $row[(int) $column] ?? '';
            }

            $error = null;
            $outcome = DB::transaction(function () use ($import, $values, &$error) {
                return $this->importRow($import, $values, $error);
            });
            $counts[$outcome.'_count']++;

            if ($error !== null && count($errors) < ContactImport::MAX_ERRORS) {
                $errors[] = ['row' => $index + 1, 'message' => $error];
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
     * @return 'created'|'updated'|'skipped'
     */
    private function importRow(ContactImport $import, array $values, ?string &$error): string
    {
        $data = $this->clean($values);

        if (($data['email'] ?? null) === false) {
            $error = "Correo no válido: «{$values['email']}».";

            return 'skipped';
        }

        if (empty($data['email']) && empty($data['document_number']) && empty($data['phone'])) {
            $error = 'Sin correo, documento ni teléfono.';

            return 'skipped';
        }

        $contact = $this->find($data);
        $isNew = $contact === null;
        $contact ??= new Contact(['source' => Contact::SOURCE_IMPORT, 'email' => $data['email'] ?? null]);

        foreach (Contact::PROFILE_FIELDS as $field) {
            if (! empty($data[$field]) && blank($contact->{$field})) {
                $contact->{$field} = $data[$field];
            }
        }

        if ($contact->email === null && ! empty($data['email'])) {
            $contact->email = $data['email'];
        }

        $role = $import->kind;
        $contact->{$role === ContactImport::KIND_ATTENDEE ? 'is_attendee' : 'is_interested'} = true;

        if ($import->has_consent && $contact->unsubscribed_at === null && ! $contact->marketing_consent) {
            $contact->grantConsent("Importación «{$import->original_name}»");
        }

        $dirty = $contact->isDirty();
        $contact->save();

        $year = $data['edition_year'] ?? $import->edition_year;
        $addedEdition = false;
        if ($year) {
            $addedEdition = ContactEdition::firstOrCreate(
                ['contact_id' => $contact->id, 'year' => $year, 'role' => $role],
                ['contact_import_id' => $import->id],
            )->wasRecentlyCreated;
        }

        return match (true) {
            $isNew => 'created',
            $dirty || $addedEdition => 'updated',
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
        $data = array_filter($data, fn ($v) => $v !== '' && $v !== '-');

        if (isset($data['full_name']) && empty($data['first_name']) && empty($data['last_name'])) {
            [$data['first_name'], $data['last_name']] = $this->splitName($data['full_name']);
        }
        unset($data['full_name']);

        foreach (['first_name', 'last_name', 'city'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Str::title(Str::lower($data[$field]));
            }
        }

        if (isset($data['email'])) {
            $email = Str::lower($data['email']);
            $data['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
        }

        if (isset($data['document_number'])) {
            $data['document_number'] = preg_replace('/[\s.\-]/', '', $data['document_number']);
        }

        if (isset($data['edition_year'])) {
            $year = (int) preg_replace('/\D/', '', $data['edition_year']);
            $data['edition_year'] = $year >= 2000 && $year <= 2100 ? $year : null;
        }

        foreach (Contact::PROFILE_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = Str::limit($data[$field], $field === 'notes' ? 2000 : 250, '');
            }
        }

        return array_filter($data, fn ($v) => $v !== null);
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
     * @param  array<string, mixed>  $data
     */
    private function find(array $data): ?Contact
    {
        if (! empty($data['email'])) {
            $contact = Contact::where('email', $data['email'])->first();
            if ($contact) {
                return $contact;
            }
        }

        if (! empty($data['document_number'])) {
            $contact = Contact::where('document_number', $data['document_number'])->first();
            // A document match with a different email is a different person sharing a typo; keep them apart.
            if ($contact && ($contact->email === null || empty($data['email']))) {
                return $contact;
            }
        }

        if (empty($data['email']) && ! empty($data['phone'])) {
            return Contact::where('phone', $data['phone'])->first();
        }

        return null;
    }

    /**
     * @param  list<string>  $row
     */
    private function isBlank(array $row): bool
    {
        return implode('', $row) === '';
    }
}
