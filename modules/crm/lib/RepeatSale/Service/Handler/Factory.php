<?php

namespace Bitrix\Crm\RepeatSale\Service\Handler;

use Bitrix\Crm\RepeatSale\Service\Context;
use Bitrix\Crm\Traits\Singleton;

final class Factory
{
	use Singleton;

	public function getHandler(
		HandlerType $handlerType,
		?string $segmentCode = null,
		?Context $context = null,
	): ?BaseHandler
	{
		if ($segmentCode)
		{
			return match ($handlerType)
			{
				HandlerType::SystemHandler => new SystemHandler($segmentCode, $context),
				HandlerType::AiScreeningHandler => new AiScreeningHandler($segmentCode, $context),
				HandlerType::AiApproveHandler => new AiApproveHandler($segmentCode, $context),
				HandlerType::ConfigurableHandler => new ConfigurableHandler($context),
				default => null,
			};
		}

		return match ($handlerType)
		{
			HandlerType::ConfigurableHandler => new ConfigurableHandler($context),
			default => null,
		};
	}
}
