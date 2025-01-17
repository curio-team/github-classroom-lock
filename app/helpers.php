<?php

if (!function_exists('user')) {
    /**
     * Returns the user that is currently logged in.
     * (With the correct model type)
     */
    function user(): \App\Models\User
    {
        return auth()->user();
    }
}

if (!function_exists('number_format_locale')) {
    /**
     * Formats a number according to the locale settings.
     */
    function number_format_locale(float $number, float $decimals = 0): string
    {
        $locale = localeconv();

        return number_format(
            $number,
            $decimals,
            $locale['decimal_point'],
            $locale['thousands_sep']
        );
    }
}
