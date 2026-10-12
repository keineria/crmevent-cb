<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * Finds the contact a set of person data belongs to. Shared by the spreadsheet importer and the plugin sync.
 *
 * Order: document number, then email (unless the document says it is someone else), then a contact saved by
 * name only, then phone plus first name, then full name. Expects data already cleaned (lowercase email,
 * document without dots or spaces, names in title case).
 */
class ContactMatcher
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function find(array $data): ?Contact
    {
        if (! empty($data['document_number'])) {
            $contact = Contact::where('document_number', $data['document_number'])->first();
            if ($contact) {
                return $contact;
            }
        }

        if (! empty($data['email'])) {
            $contact = Contact::where('email', $data['email'])->first();
            // Same email with a different document is usually a relative registered with that email, unless the
            // first name matches too (then it is the same person with a document typo in one of the files).
            if ($contact && (empty($data['document_number']) || blank($contact->document_number) || $this->sameFirstName($contact, $data))) {
                return $contact;
            }
        }

        // Someone first saved by name only (no email or document) is completed when a later file brings them.
        if (! empty($data['first_name']) && ! empty($data['last_name'])) {
            $byName = Contact::whereNull('email')->whereNull('document_number')
                ->whereRaw('LOWER(first_name) = ?', [Str::lower($data['first_name'])])
                ->whereRaw('LOWER(last_name) = ?', [Str::lower($data['last_name'])])
                ->first();
            if ($byName) {
                return $byName;
            }
        }

        if (! empty($data['email']) || ! empty($data['document_number'])) {
            return null;
        }

        // Without email or document, only a phone plus first name, or the full name, identifies someone.
        $query = Contact::query();
        if (! empty($data['phone']) && ! empty($data['first_name'])) {
            $query->where('phone', $data['phone'])->whereRaw('LOWER(first_name) = ?', [Str::lower($data['first_name'])]);
        } elseif (empty($data['phone']) && ! empty($data['first_name']) && ! empty($data['last_name'])) {
            $query->whereRaw('LOWER(first_name) = ?', [Str::lower($data['first_name'])])
                ->whereRaw('LOWER(last_name) = ?', [Str::lower($data['last_name'])]);
        } else {
            return null;
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sameFirstName(Contact $contact, array $data): bool
    {
        $first = fn (?string $name) => Str::before(ImportFields::normalize((string) $name), ' ');

        return ! empty($data['first_name']) && $first($contact->first_name) !== '' && $first($contact->first_name) === $first($data['first_name']);
    }
}
