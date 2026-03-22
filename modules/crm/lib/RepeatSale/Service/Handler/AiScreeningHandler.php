<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Service\Action\AddToAiScreeningAction;
use Bitrix\Crm\RepeatSale\Service\Operation;

final class AiScreeningHandler extends AiBaseHandler
{
	public static function getType(): HandlerType
	{
		return HandlerType::AiScreeningHandler;
	}

	protected function getOperation(Item $item, int $lastAssignmentId): Operation
	{
		// order may be important
		return (new Operation($item, $lastAssignmentId, $this->context))
			->addAction(new AddToAiScreeningAction())
		;
	}
}
