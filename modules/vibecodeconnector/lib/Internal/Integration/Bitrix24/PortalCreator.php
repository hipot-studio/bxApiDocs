<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class PortalCreator
{
	public function getId(): int
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return 0;
		}

		return (int)(ServiceLocator::getInstance()->get('bitrix24.portal.settings.creator')?->getCreatorId() ?? 0);
	}
}
