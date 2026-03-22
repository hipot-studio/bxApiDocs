<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Service\Operation;
use Bitrix\Main\SystemException;

// feature handler for custom configurable user jobs
class ConfigurableHandler extends BaseHandler
{
	public static function getType(): HandlerType
	{
		return HandlerType::ConfigurableHandler;
	}

	public function execute(): Result
	{
		throw new SystemException('Not implemented');
	}

	public function getAvailableEntityTypeIds(): array
	{
		return [];
	}

	protected function getOperation(Item $item, int $lastAssignmentId): Operation
	{
		throw new SystemException('Not implemented');
	}
}
