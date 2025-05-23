<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Integration\AI\Copilot;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Result;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Flow\Integration\AI\Agent\PromoRequestsCountUpdatedAgent;
use Bitrix\Tasks\Flow\Integration\AI\Agent\RetryAdviceGenerationAgent;
use Bitrix\Tasks\Flow\Integration\AI\Control\AdviceService;
use Bitrix\Tasks\Flow\Integration\AI\Control\Command\ReplaceAdviceCommand;
use Bitrix\Tasks\Flow\Integration\AI\Control\Command\ReplaceCollectedDataCommand;
use Bitrix\Tasks\Flow\Integration\AI\Provider\CollectedDataStatus;
use phpDocumentor\Reflection\Types\Self_;

class RequestHandler
{
	public const ERROR_CODE_LIMIT = 'LIMIT_IS_EXCEEDED';
	public const ERROR_CODE_DAILY_LIMIT = 'LIMIT_IS_EXCEEDED_DAILY';
	public const ERROR_CODE_MONTHLY_LIMIT = 'LIMIT_IS_EXCEEDED_MONTHLY';
	public const ERROR_CODE_BAAS_LIMIT = 'LIMIT_IS_EXCEEDED_BAAS';

	public static function onCompletions(Result $result, int $flowId): void
	{
		$advice = $result->getPrettifiedData();
		if (empty($advice))
		{
			return;
		}

		try
		{
			$adviceDecoded = Json::decode($advice);
		}
		catch (ArgumentException)
		{
			return;
		}

		$command =
			(new ReplaceAdviceCommand())
				->setFlowId($flowId)
				->setAdvice($adviceDecoded)
		;

		/** @var AdviceService $adviceService */
		$adviceService = ServiceLocator::getInstance()->get('tasks.flow.copilot.advice.service');
		$adviceService->replace($command);
	}

	public function onQueueJobFail($event, $flowId): void
	{
		$status = CollectedDataStatus::ERROR;

		$limitErrorCodes = [
			self::ERROR_CODE_LIMIT,
			self::ERROR_CODE_BAAS_LIMIT,
			self::ERROR_CODE_DAILY_LIMIT,
			self::ERROR_CODE_MONTHLY_LIMIT,
		];

		if (in_array($event->getCode(), $limitErrorCodes, true))
		{
			PromoRequestsCountUpdatedAgent::addAgent();

			$status = CollectedDataStatus::LIMIT_EXCEEDED;
		}
		else
		{
			RetryAdviceGenerationAgent::addAgent($flowId);
		}

		$command = new ReplaceCollectedDataCommand();
		$command->setFlowId($flowId);
		$command->setStatus($status);

		$service = ServiceLocator::getInstance()->get('tasks.flow.copilot.collected.data.service');
		$service->replace($command);
	}

	public static function onQueueJobExecute(Event $event): EventResult
	{
		$result = $event->getParameter('result');
		if (!$result instanceof Result)
		{
			return new EventResult(EventResult::ERROR);
		}

		$engine = $event->getParameter('engine');
		if (!$engine instanceof IEngine)
		{
			return new EventResult(EventResult::ERROR);
		}

		$flowId = (int)($engine->getParameters()['flowId'] ?? 0);
		if ($flowId <= 0)
		{
			return new EventResult(EventResult::ERROR);
		}

		$advice = $result->getPrettifiedData();
		if (empty($advice))
		{
			return new EventResult(EventResult::ERROR);
		}

		try
		{
			$adviceDecoded = Json::decode($advice);
		}
		catch (ArgumentException)
		{
			return new EventResult(EventResult::ERROR);
		}

		$command =
			(new ReplaceAdviceCommand())
				->setFlowId($flowId)
				->setAdvice($adviceDecoded)
		;

		$adviceService = ServiceLocator::getInstance()->get('tasks.flow.copilot.advice.service');
		$adviceService->replace($command);

		$replaceStatusCommand =
			(new ReplaceCollectedDataCommand())
				->setFlowId($flowId)
				->setStatus(CollectedDataStatus::SUCCESS)
		;

		$collectedDataService = ServiceLocator::getInstance()->get('tasks.flow.copilot.collected.data.service');
		$collectedDataService->replace($replaceStatusCommand);

		return new EventResult(EventResult::SUCCESS);
	}
}
