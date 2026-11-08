<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\V2\Feature;

class FeatureProvider
{
	public function isNewProjectsAvailable(): bool
	{
		return (
			Loader::includeModule('socialnetwork')
			&& class_exists(Feature::class)
			&& Feature::isNewProjectsOn()
		);
	}

	public function isNewProjectsOn(): bool
	{
		return Option::get('socialnetwork', 'new_projects', 'N') === 'Y';
	}

	public function canCreateProjects(): bool
	{
		return (
			Loader::includeModule('socialnetwork')
			&& Access::canCreate()
		);
	}
}
