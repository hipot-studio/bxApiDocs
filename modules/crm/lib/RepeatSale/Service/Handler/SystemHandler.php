<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Service\Action\CreateActivityAction;
use Bitrix\Crm\RepeatSale\Service\Action\CreateDealAction;
use Bitrix\Crm\RepeatSale\Service\Action\LogAction;
use Bitrix\Crm\RepeatSale\Service\Operation;

final class SystemHandler extends BaseHandler
{
	public static function getType(): HandlerType
	{
		return HandlerType::SystemHandler;
	}

	protected function getOperation(Item $item, int $lastAssignmentId): Operation
	{
		// order may be important
		return (new Operation($item, $lastAssignmentId, $this->context))
			->addAction(new CreateDealAction())
			->addAction(new CreateActivityAction())
			->addAction(new LogAction())
		;
	}

	public function getAvailableEntityTypeIds(): array
	{
		return [
			\CCrmOwnerType::Contact,
			\CCrmOwnerType::Company,
		];
	}
}
