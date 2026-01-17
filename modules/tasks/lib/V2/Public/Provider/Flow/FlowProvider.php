<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Flow;

use Bitrix\Tasks\V2\Internal\Access\Service\FlowRightService;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Flow;
use Bitrix\Tasks\V2\Internal\Repository\FlowRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Flow\FlowParams;

class FlowProvider
{
	private readonly FlowRightService $flowRightService;
	private readonly FlowRepositoryInterface $flowRepository;

	public function __construct()
	{
		$this->flowRightService = Container::getInstance()->get(FlowRightService::class);
		$this->flowRepository = Container::getInstance()->get(FlowRepositoryInterface::class);
	}

	public function get(FlowParams $flowParams): ?Flow
	{
		if ($flowParams->checkAccess && !$this->flowRightService->canView($flowParams->userId, $flowParams->flowId))
		{
			return null;
		}

		return $this->flowRepository->getById($flowParams->flowId);
	}
}
