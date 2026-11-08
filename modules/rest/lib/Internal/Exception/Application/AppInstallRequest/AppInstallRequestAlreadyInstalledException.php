<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Application\AppInstallRequest;

class AppInstallRequestAlreadyInstalledException extends AppInstallRequestException
{
	public const CODE = 'already_installed';

	protected function getMessagePhrase(): string
	{
		return 'REST_APP_INSTALL_REQUEST_EXCEPTION_ALREADY_INSTALLED';
	}
}
