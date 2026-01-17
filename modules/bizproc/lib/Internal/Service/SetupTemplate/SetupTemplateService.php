<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\SetupTemplate;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Event\SetupTemplateUserInputEvent;
use Bitrix\Bizproc\Internal\Event\SetupTemplateValidationEvent;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;

class SetupTemplateService
{
	private ?ErrorCollection $errors = null;
	private bool $validationEventReceived = false;
	private ?int $eventHandler = null;

	/**
	 * @param int $userId User identifier
	 * @param string $instanceId Identifier of workflow instance
	 * @param int $templateId Identifier of workflow template
	 * @param array<string, string> $constantValues [constantId => constantValue, ...]
	 *
	 * @return Result
	 */
	public function fill(
		int $userId,
		int $templateId,
		string $instanceId,
		array $constantValues = []
	): Result
	{
		$this->listenForValidationEvent($userId, $templateId);
		$this->sendEvent($templateId, $userId, $instanceId, $constantValues);
		$this->stopListenValidationEvent();

		$result = new Result();
		if ($this->errors instanceof ErrorCollection)
		{
			$result->addErrors($this->errors->getValues());
		}
		if (!$this->validationEventReceived)
		{
			$result->addError(ErrorMessage::BP_NOT_FOUND->getError());
		}

		return $result;
	}

	private function listenForValidationEvent(int $userId, int $templateId): void
	{
		$this->errors = null;
		$this->validationEventReceived = false;
		$this->eventHandler = EventManager::getInstance()
			->addEventHandler(
				fromModuleId: SetupTemplateValidationEvent::MODULE_ID,
				eventType: SetupTemplateValidationEvent::EVENT_NAME,
				callback: function(SetupTemplateValidationEvent $event) use ($userId, $templateId)
				{
					if ($event->getTemplateId() === $templateId && $event->getUserId() === $userId)
					{
						$this->errors = $event->getErrors();
						$this->validationEventReceived = true;
					}
				}
			)
		;
	}

	private function sendEvent(
		int $templateId,
		int $userId,
		string $instanceId,
		array $constantValues,
	): void
	{
		(new SetupTemplateUserInputEvent(
			// parameter order is important
			parameters: [
				SetupTemplateUserInputEvent::PARAMETER_INSTANCE_ID => $instanceId,
				SetupTemplateUserInputEvent::PARAMETER_USER_ID => $userId,
				SetupTemplateUserInputEvent::PARAMETER_TEMPLATE_ID => $templateId,
				SetupTemplateUserInputEvent::PARAMETER_CONSTANT_VALUES => $constantValues,
			]
		))
			->send()
		;
	}

	private function stopListenValidationEvent(): void
	{
		if (!$this->eventHandler)
		{
			return;
		}

		EventManager::getInstance()
			->removeEventHandler(
				fromModuleId: SetupTemplateValidationEvent::MODULE_ID,
				eventType: SetupTemplateValidationEvent::EVENT_NAME,
				iEventHandlerKey: $this->eventHandler,
			)
		;
	}
}