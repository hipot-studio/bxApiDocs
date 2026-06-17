<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Debug;

use Bitrix\Bizproc\Internal\Service\Debugger\DebugService;
use Bitrix\Bizproc\Internal\Service\Debugger\DebugServiceInterface;

class DisableDebugCommandHandler
{
	private readonly DebugServiceInterface $service;

	public function __construct()
	{
		$this->service = new DebugService();
	}

	public function __invoke(DisableDebugCommand $command): void
	{
		$this->service->disable($command->userId, $command->templateId, $command->documentId);
	}
}
