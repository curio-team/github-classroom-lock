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
