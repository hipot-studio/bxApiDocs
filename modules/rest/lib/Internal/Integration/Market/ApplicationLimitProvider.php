<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Market;

use Bitrix\Main\Loader;
use Bitrix\Market\Application\ApplicationLimit;

class ApplicationLimitProvider
{
	public function getProjection(): ?array
	{
		try
		{
			if (!Loader::includeModule('market'))
			{
				return null;
			}

			return (new ApplicationLimit())->getProjection();
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
