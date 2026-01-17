<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAvailabilityService;

abstract class BizprocDesignerTool extends ToolContract
{
	private readonly AiAvailabilityService $availabilityService;
	public function __construct(
		TracedLogger $tracedLogger,
		?AiAvailabilityService $availabilityService = null,
	)
	{
		parent::__construct($tracedLogger);
		$this->availabilityService = $availabilityService ?? new AiAvailabilityService();
	}

	public function canList(int $userId): bool
	{
		return $this->isAllowedToUseBizprocDesigner($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->isAllowedToUseBizprocDesigner($userId);
	}

	protected function isAllowedToUseBizprocDesigner(int $userId): bool
	{
		// @TODO is allowed to use bizproc designer
		return $this->availabilityService->isAvailableForUser($userId);
	}
}