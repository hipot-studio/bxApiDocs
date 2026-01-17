<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Stakeholder;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\MemberService;

class SetAuditorsHandler
{
	public function __construct(
		private readonly MemberService $memberService,
	)
	{

	}

	public function __invoke(SetAuditorsCommand $command): Task
	{
		return $this->memberService->setAuditors($command->taskId, $command->auditorIds, $command->config);
	}
}
