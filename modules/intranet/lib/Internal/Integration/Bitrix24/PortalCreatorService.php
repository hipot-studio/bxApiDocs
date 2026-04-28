<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Portal\Settings\EmailConfirmationRequirements\Type;
use Bitrix\Bitrix24\Service\PortalSettings;
use Bitrix\Main\Loader;

class PortalCreatorService
{

	protected bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');
	}

	public function isPortalCreatorEmailConfirmed(): bool
	{
		if (!$this->isModuleIncluded)
		{
			return true;
		}

		return
			!PortalSettings::getInstance()
			->getEmailConfirmationRequirements()
			->isRequiredByType(Type::INVITE_USERS)
		;
	}
}
