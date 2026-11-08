<?php

namespace Bitrix\Crm\Controller\RepeatSale;

use Bitrix\Crm\RepeatSale\FlowController;
use Bitrix\Crm\RepeatSale\Log\Controller\RepeatSaleLogController;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Service\Container;

class Start extends Base
{
	public function getDataAction(): array
	{
		$container = Container::getInstance();
		$availabilityChecker = $container->getRepeatSaleAvailabilityChecker();
		$logController = RepeatSaleLogController::getInstance();
		if (!$availabilityChecker->isEnablePending() && $logController->getList()->isEmpty())
		{
			return [
				'isFlowStarted' => true,
			];
		}

		return [
			'flowExpectedEnableTimestamp' => FlowController::getInstance()->getExpectedEnableDate()?->getTimestamp(),
			'canEnableFeature' => $container->getUserPermissions()->repeatSale()->canEdit(),
			'count' => RepeatSaleSegmentController::getInstance()->getFoundClientsCount(),
		];
	}
}
