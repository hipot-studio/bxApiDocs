<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Localization\Loc;

/**
 * "Resume" item (TPL-D1). Visible with edit rights and a paused recurrence;
 * mutually exclusive with {@see PauseRowAction} by repeatStatus (paused -> resume).
 */
final class ResumeRowAction extends ReplicationRowAction
{
	public static function getId(): ?string
	{
		return 'resume';
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_ROW_ACTION_RESUME');
	}

	protected function isVisible(array $rawFields): bool
	{
		return $this->canEdit($rawFields) && $this->getRepeatStatus($rawFields) === 'paused';
	}
}
