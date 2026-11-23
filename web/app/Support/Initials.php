<?php

namespace App\Support;

/** One definition of "initials" for every avatar in the application. */
class Initials
{
    private const SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];

    public static function from(?string $name): string
    {
        $parts = collect(preg_split('/\s+/u', trim((string) $name)) ?: [])->filter()
            ->reject(fn (string $part) => in_array(mb_strtolower(rtrim($part, '.,')), self::SUFFIXES, true))->values();
        if ($parts->isEmpty()) {
            return '?';
        }
        $first = mb_strtoupper(mb_substr($parts->first(), 0, 1));
        $last = $parts->count() > 1 ? mb_strtoupper(mb_substr($parts->last(), 0, 1)) : '';

        return $first.$last;
    }
}
