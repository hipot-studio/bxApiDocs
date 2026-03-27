<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\LimitEncounter\DocumentEditSession;

use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Internal\Command\LimitEncounter\IncrementLimitEncounterCountCommand;
use Bitrix\Disk\Internal\Enum\LimitEncounterType;
use Bitrix\Disk\Internal\Service\LimitEncounter\DocumentEditSession\Notification\ThresholdReachedNotifier;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandler;
use Throwable;

class LimitEncounterThresholdProcessor
{
	private const THRESHOLD_VALUES = [
		LimitEncounterType::DocumentEditSession->value => [
			5,
			10,
		],
	];
	private ExceptionHandler $exceptionHandler;

	/**
	 * @param BaasSessionBoostService $baasSessionBoostService
	 * @param ThresholdReachedNotifier $notifier
	 */
	public function __construct(
		private readonly BaasSessionBoostService $baasSessionBoostService,
		private readonly ThresholdReachedNotifier $notifier,
	)
	{
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	/**
	 * @param LimitEncounterType $type
	 * @return void
	 */
	public function process(LimitEncounterType $type): void
	{
		if (!$this->baasSessionBoostService->isAvailable())
		{
			return;
		}

		$thresholdValues = self::THRESHOLD_VALUES[$type->value];
		$maxThresholdValue = max($thresholdValues);

		try
		{
			$itemsCountResult = (new IncrementLimitEncounterCountCommand(
				type: $type,
				max: $maxThresholdValue,
			))->run();
		}
		catch (Throwable $e)
		{
			$this->exceptionHandler->handleException($e);

			return;
		}

		if (!$itemsCountResult->hasCount())
		{
			return;
		}

		$count = $itemsCountResult->getCount() ?? 0;

		$thresholdIndex = array_search($count, $thresholdValues, true);
		if ($thresholdIndex !== false)
		{
			$thresholdValue = $thresholdValues[$thresholdIndex];

			try
			{
				$this->notifier->notify($thresholdIndex, $thresholdValue);
			}
			catch (Throwable $e)
			{
				$this->exceptionHandler->handleException($e);
			}
		}
	}
}
