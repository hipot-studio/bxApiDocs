<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Recent;

use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Tasks\V2\Internal\Async\QueueId;
use Bitrix\Tasks\V2\Internal\Service\Template\TemplateRecentService;

class UpdateTemplateRecentReceiver extends AbstractReceiver
{
	public function __construct(
		private readonly TemplateRecentService $service,
	)
	{
	}

	/* @var UpdateTemplateRecentMessage $message */
	protected function process(MessageInterface $message): void
	{
		$userId = $message->userId;
		$templateId = $message->templateId;
		$action = $message->action;

		match ($action)
		{
			UpdateTemplateRecentMessage::ACTION_ADD => $this->service->executeAdd($userId, $templateId),
			UpdateTemplateRecentMessage::ACTION_REMOVE => $this->service->executeRemove($userId, $templateId),
		};
	}

	protected function getQueueId(): QueueId
	{
		return QueueId::TemplateRecent;
	}
}
