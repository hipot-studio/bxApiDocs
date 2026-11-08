<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Application\AppInstallRequest;

class AppInstallRequestNotAllowedException extends AppInstallRequestException
{
	public const CODE = 'denied';

	protected function getMessagePhrase(): string
	{
		return 'REST_APP_INSTALL_REQUEST_EXCEPTION_NOT_ALLOWED';
	}
}
