<?php

declare(strict_types=1);

namespace Glueful\Extensions\Enums;

enum ExtensionStatus: string
{
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';
    case LOADING = 'loading';
    case ERROR = 'error';
    case INSTALLING = 'installing';
}
