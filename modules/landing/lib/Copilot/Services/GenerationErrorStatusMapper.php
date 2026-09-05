<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Metrika;

/**
 * Translates a failure of the generation into the analytics status of the event.
 * The limit checker puts a ready status into the params of the exception - it wins over the code.
 */
class GenerationErrorStatusMapper
{
	public static function resolve(GenerationException $e): Metrika\Statuses
	{
		return match ($e->getErrorCode())
		{
			GenerationErrors::requestQuotaExceeded => $e->getParams()['metrikaStatus'] ?? Metrika\Statuses::ErrorB24,
			GenerationErrors::restrictedRequest => $e->getParams()['metrikaStatus'] ?? Metrika\Statuses::ErrorContentPolicy,
			GenerationErrors::notExistResponse,
			GenerationErrors::notFullyResponse,
			GenerationErrors::notCorrectResponse => Metrika\Statuses::ErrorProvider,
			default => Metrika\Statuses::ErrorB24,
		};
	}
}
