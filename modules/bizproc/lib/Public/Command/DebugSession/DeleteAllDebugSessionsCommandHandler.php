<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\DebugSession;

use Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepository;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepositoryInterface;

class DeleteAllDebugSessionsCommandHandler
{
	private readonly DebugSessionRepositoryInterface $repository;

	public function __construct()
	{
		$this->repository = new DebugSessionRepository();
	}

	public function __invoke(DeleteAllDebugSessionsCommand $command): int
	{
		return $this->repository->deleteByUserId($command->userId);
	}
}
