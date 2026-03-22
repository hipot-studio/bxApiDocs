<?php

namespace Bitrix\Crm\Copilot\AiQueueBuffer\Provider;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ScreeningRepeatSaleItemProvider implements QueueBufferProviderInterface
{
	public static function getId(): int
	{
		return 2;
	}

	public function process(?array $data = null): Result
	{
		$result = new Result();

		if (empty($data))
		{
			$error = new Error('Provider data must be specified', 'PROVIDER_DATA_EMPTY');

			return $result->addError($error);
		}

		return AIManager::launchScreeningRepeatSaleItem(
			new ItemIdentifier($data['clientEntityTypeId'] ?? 0, $data['clientEntityId'] ?? 0),
			$data['segmentId'] ?? 0,
			$data['clientIdentifiers'] ?? [],
		);
	}
}
