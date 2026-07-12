<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\ValidatePassword;

enum ExternalLinkValidatePasswordError: string
{
	/** @see \Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessChecker::onBeforeAction */
	case Forbidden = 'FORBIDDEN';
	case InvalidPassword = 'INVALID_PASSWORD';
	case ExternalLinkNotFound = 'EXTERNAL_LINK_NOT_FOUND';
}
