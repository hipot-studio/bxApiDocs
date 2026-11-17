<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Localization\Loc;

/**
 * "Edit recurrence" item (TPL-D1). Visible with edit rights (AC-S-038).
 */
final class EditRepeatRowAction extends ReplicationRowAction
{
	public static function getId(): ?string
	{
		return 'editRepeat';
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_ROW_ACTION_EDIT_REPEAT');
	}

	protected function isVisible(array $rawFields): bool
	{
		return $this->canEdit($rawFields);
	}
}
