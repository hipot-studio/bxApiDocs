<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Application\AppInstallRequest;

class AppInstallRequestApplicationNotFoundException extends AppInstallRequestException
{
	public const CODE = 'not_found';

	protected function getMessagePhrase(): string
	{
		return 'REST_APP_INSTALL_REQUEST_EXCEPTION_NOT_FOUND';
	}
}
