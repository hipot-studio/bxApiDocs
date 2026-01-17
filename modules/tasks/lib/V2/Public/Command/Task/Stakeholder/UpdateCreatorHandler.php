<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Stakeholder;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\MemberService;

class UpdateCreatorHandler
{
	public function __construct(
		private readonly MemberService $memberService,
	)
	{

	}

	public function __invoke(UpdateCreatorCommand $command): Task
	{
		return $this->memberService->updateCreator(
			$command->taskId,
			$command->creatorId,
			$command->responsibleId,
			$command->config
		);
	}
}
