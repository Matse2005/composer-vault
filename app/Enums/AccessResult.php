<?php

namespace App\Enums;

enum AccessResult: string
{
    case Ok = 'ok';
    case UnknownToken = 'unknown_token';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case IpDenied = 'ip_denied';
    case UnknownProject = 'unknown_project';
    case ProjectDenied = 'project_denied';
    case RateLimited = 'rate_limited';
}
