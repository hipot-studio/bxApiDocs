<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\Debug;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Service\Debugger\DebugService;
use Bitrix\Bizproc\Internal\Service\Debugger\DebugServiceInterface;

class EnableDebugForTemplateCommandHandler
{
	private readonly DebugServiceInterface $service;

	public function __construct()
	{
		$this->service = new DebugService();
	}

	public function __invoke(EnableDebugForTemplateCommand $command): Debug
	{
		return $this->service->enableForTemplate($command->userId, $command->templateId);
	}
}
