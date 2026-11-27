<?php

namespace App\Enums;

enum CredentialType: string
{
    case HttpBasic = 'http-basic';
    case Bearer = 'bearer';
    case GithubOauth = 'github-oauth';
    case GitlabToken = 'gitlab-token';

    /**
     * Get the name shown in the admin interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::HttpBasic => 'HTTP basic',
            self::Bearer => 'Bearer token',
            self::GithubOauth => 'GitHub OAuth token',
            self::GitlabToken => 'GitLab token',
        };
    }

    /**
     * Determine whether this type is stored with a username.
     */
    public function requiresUsername(): bool
    {
        return $this === self::HttpBasic;
    }
}
