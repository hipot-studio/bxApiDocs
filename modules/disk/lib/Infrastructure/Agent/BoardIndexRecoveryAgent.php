<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

use Bitrix\Disk\Search\BoardIndexRecoveryService;

final class BoardIndexRecoveryAgent extends BaseDiskAgent
{
	public function __construct(
		private ?BoardIndexRecoveryService $recoveryService = null,
	)
	{
		$this->recoveryService ??= new BoardIndexRecoveryService();
	}

	protected function runInternal(): RunResult
	{
		$this->recoveryService->recover();

		return new RunResult(true);
	}
}
