<?php

namespace Bitrix\Crm\Copilot\AiQueueBuffer;

use Bitrix\Crm\Copilot\AiQueueBuffer\Provider\FillRepeatSaleTipsProvider;
use Bitrix\Crm\Copilot\AiQueueBuffer\Provider\QueueBufferProviderInterface;
use Bitrix\Crm\Copilot\AiQueueBuffer\Provider\ScreeningRepeatSaleItemProvider;

final class Factory
{
	public static function getProvider(int $providerId): ?QueueBufferProviderInterface
	{
		return match ($providerId)
		{
			FillRepeatSaleTipsProvider::getId() => new FillRepeatSaleTipsProvider(),
			ScreeningRepeatSaleItemProvider::getId() => new ScreeningRepeatSaleItemProvider(),
			default => null,
		};
	}
}
