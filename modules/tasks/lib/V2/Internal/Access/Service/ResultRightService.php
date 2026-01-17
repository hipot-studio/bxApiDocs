<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\Model\ResultModel;
use Bitrix\Tasks\Access\ResultAccessCacheLoader;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

class ResultRightService
{
	use ModelRightsTrait;

	public function __construct(
		private readonly ResultAccessCacheLoader $resultAccessCacheLoader,
		private readonly ControllerFactoryInterface $controllerFactory,
	)
	{

	}

	public function getResultRightsBatch(int $userId, array $resultIds, array $rules = ActionDictionary::RESULT_ACTIONS, array $params = []): array
	{
		$this->resultAccessCacheLoader->preload($resultIds);

		$access = [];
		foreach ($resultIds as $resultId)
		{
			$access[$resultId] = $this->get($rules, $resultId, $userId, $params);
		}

		return $access;
	}

	public function get(array $rules, int $resultId, int $userId, array $params = []): array
	{
		return $this->getModelRights(
			type: Type::Result,
			controllerFactory: $this->controllerFactory,
			rules: $rules,
			item: ResultModel::createFromId($resultId),
			userId: $userId,
			params: $params,
		);
	}
}
