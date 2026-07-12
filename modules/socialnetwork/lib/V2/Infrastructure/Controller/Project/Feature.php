<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller\Project;

use Bitrix\Socialnetwork\V2\Infrastructure\Controller\BaseController;
use Bitrix\Socialnetwork\V2\Infrastructure\Controller\Trait\ProjectAutoWireTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;

class Feature extends BaseController
{
	use ProjectAutoWireTrait;

	public function getAutoWiredParameters(): array
	{
		return $this->getProjectAutoWiredParameters();
	}

	/**
	 * @ajaxAction socialnetwork.V2.Project.Feature.getAvailableFeatures
	 */
	public function getAvailableFeaturesAction(
		#[Permission\Create]
		Dto\Project\Project $project,
		ProjectProvider $projectProvider,
	): ?array
	{
		return $projectProvider->getAvailableFeaturesForCreate()->toArray();
	}
}
