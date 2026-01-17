<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Registry\Preload\ElapsedTimeAccessCachePreloader;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed\ElapsedTimeModel;

class ElapsedTimeRightService
{
	use ModelRightsTrait;

	public function __construct(
		private readonly ControllerFactoryInterface $controllerFactory,
		private readonly ElapsedTimeAccessCachePreloader $accessCacheLoader,
	)
	{

	}

	public function getElapsedTimeRightsBatch(int $userId, array $elapsedTimeIds, array $rules = ActionDictionary::ELAPSED_TIME_ACTIONS, array $params = []): array
	{
		$this->accessCacheLoader->preload($elapsedTimeIds);

		$access = [];
		foreach ($elapsedTimeIds as $elapsedTimeId)
		{
			$access[$elapsedTimeId] = $this->get($rules, $elapsedTimeId, $userId, $params);
		}

		return $access;
	}

	public function get(array $rules, int $elapsedTimeId, int $userId, array $params = []): array
	{
		return $this->getModelRights(
			type: Type::ElapsedTime,
			controllerFactory: $this->controllerFactory,
			rules: $rules,
			item: ElapsedTimeModel::createFromId($elapsedTimeId),
			userId: $userId,
			params: $params,
		);
	}
}
