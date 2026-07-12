<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Feature;

class FeatureService
{
	private readonly bool $moduleInstalled;

	public function __construct()
	{
		$this->moduleInstalled = (bool)Loader::includeModule('socialnetwork');
	}

	public function isNewProjectsOn(): bool
	{
		if (!$this->moduleInstalled)
		{
			return false;
		}

		return class_exists(Feature::class) && Feature::isNewProjectsOn();
	}
}
