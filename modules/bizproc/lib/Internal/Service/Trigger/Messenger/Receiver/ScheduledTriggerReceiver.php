<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Messenger\Receiver;

use Bitrix\Bizproc\Internal\Service\Trigger\Messenger\Entity\ScheduledTriggerMessage;
use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleSyncService;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class ScheduledTriggerReceiver extends AbstractReceiver
{
	/**
	 * @param ScheduledTriggerMessage $message
	 */
	protected function process(MessageInterface $message): void
	{
		if (($message instanceof ScheduledTriggerMessage) === false)
		{
			throw new UnprocessableMessageException($message);
		}

		Starter::getByScenario(Scenario::onEvent)
			->setTemplateIds([$message->templateId])
			->addEvent(
				ScheduleSyncService::TRIGGER_TYPE,
				[],
				[
					'scheduleId' => $message->scheduleId,
					'triggerName' => $message->triggerName,
					'scheduledAt' => $message->scheduledAt,
				],
			)
			->start()
		;
	}
}
