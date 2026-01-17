<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Stakeholder;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\MemberService;

class DeleteAuditorsHandler
{
	public function __construct(
		private readonly MemberService $memberService,
	)
	{

	}

	public function __invoke(DeleteAuditorsCommand $command): Task
	{
		return $this->memberService->deleteAuditors($command->taskId, $command->auditorIds, $command->config);
	}
}
