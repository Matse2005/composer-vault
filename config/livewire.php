<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CSP Safe
    |--------------------------------------------------------------------------
    |
    | The admin interface runs under a Content Security Policy without
    | 'unsafe-eval'. Livewire then has to use its CSP-safe Alpine build, which
    | does not evaluate expressions with eval(). Components that need methods
    | or arrow functions register them with Alpine.data() in
    | resources/js/alpine-components.js instead of writing them inline.
    |
    */

    'csp_safe' => true,

];
