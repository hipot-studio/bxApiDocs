<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main;

class AppCollection extends Main\Entity\EntityCollection
{
	protected static function getEntityClass(): string
	{
		return App::class;
	}

	public function getAppCodes(): array
	{
		return $this->map(function ($application)
		{
			return $application->getCode();
		});
	}
}
