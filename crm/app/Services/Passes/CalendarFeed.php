<?php

namespace App\Services\Passes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * iCalendar (.ics) file of a participant's activities, readable by Apple Calendar, Outlook and Google Calendar,
 * plus "add to Google Calendar" links for single activities.
 */
class CalendarFeed
{
    /**
     * @param  Collection<int, array{id: int, name: string, type: string|null, date: string, all_day: bool, start: CarbonImmutable, end: CarbonImmutable, location: string|null}>  $entries
     */
    public function ics(Collection $entries, string $calendarName): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'crm';
        $stamp = now()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Colombia Birdfair//CRM 360//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName),
            'X-WR-TIMEZONE:'.ParticipantSchedule::TIMEZONE,
            // Subscribed calendars check again every few hours, so a changed outing shows up on the phone.
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($entries as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:cbf-activity-{$e['id']}@{$host}";
            $lines[] = "DTSTAMP:{$stamp}";
            if ($e['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e['start']->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$e['end']->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.self::utc($e['start']);
                $lines[] = 'DTEND:'.self::utc($e['end']);
            }
            $lines[] = 'SUMMARY:'.self::escape($e['name']);
            if ($e['location']) {
                $lines[] = 'LOCATION:'.self::escape($e['location']);
            }
            $lines[] = 'DESCRIPTION:'.self::escape($calendarName.'. Presenta tu código QR en el ingreso.');
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @param  array{name: string, all_day: bool, start: CarbonImmutable, end: CarbonImmutable, location: string|null}  $entry
     */
    public function googleLink(array $entry, string $details): string
    {
        $dates = $entry['all_day']
            ? $entry['start']->format('Ymd').'/'.$entry['end']->format('Ymd')
            : self::utc($entry['start']).'/'.self::utc($entry['end']);

        return 'https://calendar.google.com/calendar/render?'.http_build_query(array_filter([
            'action' => 'TEMPLATE',
            'text' => $entry['name'],
            'dates' => $dates,
            'ctz' => ParticipantSchedule::TIMEZONE,
            'details' => $details,
            'location' => $entry['location'],
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Link that subscribes Google Calendar to a feed, so every activity appears at once and later changes follow.
     */
    public function googleSubscribeLink(string $feedUrl): string
    {
        return 'https://calendar.google.com/calendar/r?cid='.rawurlencode(preg_replace('#^https?://#', 'webcal://', $feedUrl));
    }

    private static function utc(CarbonImmutable $time): string
    {
        return $time->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /**
     * Lines longer than 75 octets continue on the next line after a space (RFC 5545 §3.1), without splitting a character.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $char;
        }

        return $out."\r\n ".$current;
    }
}
