<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\ProgrammaticMessageMarker;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionOrigin;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionRecorder;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;
use Bitrix\Tasks\V2\Internal\Service\Ranking\RankingConfig;

/**
 * Captures a `comment` interaction when a user posts a real message into the task
 * chat: only a non-system, non-bot message left unmarked by
 * {@see \Bitrix\Tasks\V2\Internal\Integration\Im\ProgrammaticMessageMarker} counts.
 */
class RecordInteraction
{
	public function __construct(
		private readonly InteractionRecorder $interactionRecorder,
		private readonly ProgrammaticMessageMarker $programmaticMessageMarker,
	)
	{
	}

	public function __invoke(AfterSendMessageEvent $event): void
	{
		if (!RankingConfig::isEnabled())
		{
			return;
		}

		if ($event->getMessage()->isSystem())
		{
			return;
		}

		if ($this->programmaticMessageMarker->isProgrammatic($event->getMessage()))
		{
			return;
		}

		$authorId = $event->getMessage()->getAuthorId();
		if ($authorId === 0)
		{
			return;
		}

		if ($event->getMessage()->getAuthor()?->isBot())
		{
			return;
		}

		$taskId = (int)$event->getChat()->getEntityId();
		if ($taskId <= 0)
		{
			return;
		}

		$this->interactionRecorder->record(
			userId: $authorId,
			taskId: $taskId,
			eventType: EventType::Comment,
			relationKind: null,
			origin: InteractionOrigin::Ui,
		);
	}
}
