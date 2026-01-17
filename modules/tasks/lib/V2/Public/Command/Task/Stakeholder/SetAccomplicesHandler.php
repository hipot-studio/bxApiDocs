<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Stakeholder;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\MemberService;

class SetAccomplicesHandler
{
	public function __construct(
		private readonly MemberService $memberService,
	)
	{

	}

	public function __invoke(SetAccomplicesCommand $command): Task
	{
		return $this->memberService->setAccomplices($command->taskId, $command->accompliceIds, $command->config);
	}
}
