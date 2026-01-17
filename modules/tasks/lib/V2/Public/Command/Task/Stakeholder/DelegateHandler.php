<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Stakeholder;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\MemberService;

class DelegateHandler
{
	public function __construct(
		private readonly MemberService $memberService,
	)
	{

	}

	public function __invoke(DelegateCommand $command): Task
	{
		return $this->memberService->delegate($command->taskId, $command->responsibleId, $command->config);
	}
}
