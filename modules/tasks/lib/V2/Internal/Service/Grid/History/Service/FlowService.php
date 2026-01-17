<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\Flow\FlowCollection;
use Bitrix\Tasks\Flow\Provider\Exception\ProviderException;
use Bitrix\Tasks\Flow\Provider\FlowProvider;
use Bitrix\Tasks\Flow\Provider\Query\ExpandedFlowQuery;

class FlowService
{
	public function getFlows(array $flowIds, int $userId): FlowCollection
	{
		if (empty($flowIds))
		{
			return new FlowCollection();
		}

		$query = (new ExpandedFlowQuery($userId))
			->setSelect(['ID', 'NAME'])
			->whereId($flowIds, 'in');
		$provider = new FlowProvider();

		try
		{
			return $provider->getList($query);
		}
		catch (ProviderException)
		{
			return new FlowCollection();
		}
	}

	public function fillFlow(FlowCollection $flowEntities, int $flowId): ?array
	{
		if ($flowId <= 0)
		{
			return null;
		}

		if (isset($flowEntities[$flowId]))
		{
			return [
				'id' => $flowId,
				'name' => $flowEntities[$flowId]->getName(),
			];
		}

		return [];
	}
}
