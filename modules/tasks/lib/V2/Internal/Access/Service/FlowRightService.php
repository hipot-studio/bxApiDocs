<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Flow\Access\FlowModel;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

class FlowRightService
{
	use ModelRightsTrait;
	use UserRightsTrait;

	public function __construct(
		private readonly ControllerFactoryInterface $controllerFactory,
	)
	{

	}

	public function canView(int $flowId, int $userId): bool
	{
		$rights = $this->getModelRights(
			type: Type::Flow,
			controllerFactory: $this->controllerFactory,
			rules: ['read' => ActionDictionary::FLOW_ACTIONS['read']],
			item: FlowModel::createFromId($flowId),
			userId: $userId,
		);

		return $rights['read'] ?? false;
	}


	public function getUserRights(int $userId, array $rules = ActionDictionary::USER_ACTIONS['flow']): array
	{
		return $this->getUserRightsByType(
			userId: $userId,
			rules: $rules,
			type: Type::Flow,
			controllerFactory: $this->controllerFactory,
		);
	}
}
