<?php

namespace Bitrix\Call\Controller;

use Bitrix\Im\Call\Registry;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Call\Error;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\DTO\ProcessingStatusRequest;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\CallAIService;
use Bitrix\Call\Controller\Filter\UniqueRequestFilter;

class Mixer extends JwtController
{
	public function getAutoWiredParameters(): array
	{
		return array_merge([
			new ExactParameter(
				ProcessingStatusRequest::class,
				'statusRequest',
				$this->decodeJwtParameter()
			),
		], parent::getAutoWiredParameters());
	}

	public function configureActions()
	{
		return [
			'processingStatus' => [
				'+prefilters' => [
					new UniqueRequestFilter(),
				],
			],
		];
	}


	/**
	 * @restMethod call.Mixer.processingStatus
	 */
	public function processingStatusAction(ProcessingStatusRequest $statusRequest): ?array
	{
		Loader::includeModule('im');

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$call = Registry::getCallWithUuid($statusRequest->roomId);
		if (!$call)
		{
			$log && $logger->error("Call roomId:{$statusRequest->roomId} not found");
			$this->addError(new Error(Error::CALL_NOT_FOUND));
			return null;
		}

		$log && $logger->info("Got processing status update for call #{$call->getId()}: {$statusRequest->status}" .
			($statusRequest->message ? " - {$statusRequest->message}" : ""));

		// Update AI agent expectation time when mixer reports processing status
		if (CallAISettings::isCallAIEnable() && $call->isAiAnalyzeEnabled())
		{
			CallAIService::getInstance()->updateExpectationTime($call->getId());
			$log && $logger->info("Updated AI expectation time for call #{$call->getId()} after processing status update");
		}

		return ['result' => true];
	}
}
