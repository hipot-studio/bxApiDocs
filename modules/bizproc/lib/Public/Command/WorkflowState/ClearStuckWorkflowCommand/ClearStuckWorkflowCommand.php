<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

class ClearStuckWorkflowCommand extends AbstractCommand
{
	private const DEFAULT_LIMIT = 50;
	public readonly int $limit;

	public function __construct(readonly ?DateTime $startedDate)
	{
		$this->limit = (int)Option::get('bizproc', 'clear_stuck_workflow_limit', self::DEFAULT_LIMIT);
	}

	protected function execute(): ClearStuckWorkflowResult
	{
		$handler = Container::getClearStuckWorkflowCommandHandler();
		$startedDate = $handler($this);

		return new ClearStuckWorkflowResult($startedDate);
	}
}
