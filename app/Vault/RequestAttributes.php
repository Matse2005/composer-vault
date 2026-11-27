<?php

namespace App\Vault;

/**
 * Names of the request attributes that the API middleware, the controller and the access logger share.
 */
final class RequestAttributes
{
    /** The AccessResult of the request. */
    public const ACCESS_RESULT = 'vault.access_result';

    /** The authenticated AccessToken, when a token was found. */
    public const ACCESS_TOKEN = 'vault.access_token';

    /** The Project that was served. */
    public const PROJECT = 'vault.project';

    /** The project slug from the URL, for requests that did not reach a project. */
    public const REQUESTED_SLUG = 'vault.requested_slug';
}
