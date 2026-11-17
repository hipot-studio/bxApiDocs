<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Localization\Loc;

/**
 * "Pause" item (TPL-D1). Visible with edit rights and an active recurrence;
 * mutually exclusive with {@see ResumeRowAction} by repeatStatus (active -> pause).
 */
final class PauseRowAction extends ReplicationRowAction
{
	public static function getId(): ?string
	{
		return 'pause';
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_ROW_ACTION_PAUSE');
	}

	protected function isVisible(array $rawFields): bool
	{
		return $this->canEdit($rawFields) && $this->getRepeatStatus($rawFields) === 'active';
	}
}
