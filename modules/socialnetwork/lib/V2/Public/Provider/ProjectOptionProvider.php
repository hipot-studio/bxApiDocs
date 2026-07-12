<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectOptionService;

final class ProjectOptionProvider
{
	private ProjectOptionService $projectOptionService;

	public function __construct()
	{
		$this->projectOptionService = Container::getInstance()->getProjectOptionService();
	}

	public function isCollabConverted(int $collabId): bool
	{
		return $this->projectOptionService->isCollabConverted($collabId);
	}

	public function isCollabConvertedBatch(array $collabIds): array
	{
		return $this->projectOptionService->isCollabConvertedBatch($collabIds);
	}
}
