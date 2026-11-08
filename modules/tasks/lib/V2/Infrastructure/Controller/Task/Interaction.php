<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionOrigin;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionRecorder;
use Bitrix\Tasks\V2\Internal\Service\Ranking\EventType;

class Interaction extends BaseController
{
	/**
	 * Engagement signal from the client: the task card was open long enough to count
	 * as engagement. The recorder throttles and writes asynchronously.
	 *
	 * @ajaxAction tasks.V2.Task.Interaction.open
	 */
	#[CloseSession]
	public function openAction(
		#[Permission\Read]
		Entity\Task $task,
		InteractionRecorder $interactionRecorder,
	): array
	{
		$interactionRecorder->record(
			userId: $this->userId,
			taskId: (int)$task->id,
			eventType: EventType::Open,
			relationKind: null,
			origin: InteractionOrigin::Ui,
		);

		return ['result' => true];
	}
}
