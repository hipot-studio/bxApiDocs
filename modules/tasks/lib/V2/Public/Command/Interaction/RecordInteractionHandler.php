<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Interaction;

use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionRecorder;

class RecordInteractionHandler
{
	public function __construct(
		private readonly InteractionRecorder $interactionRecorder,
	)
	{
	}

	public function __invoke(RecordInteractionCommand $command): void
	{
		$this->interactionRecorder->record(
			userId: $command->userId,
			taskId: $command->taskId,
			eventType: $command->eventType,
			relationKind: $command->relationKind,
			origin: $command->origin,
		);
	}
}
