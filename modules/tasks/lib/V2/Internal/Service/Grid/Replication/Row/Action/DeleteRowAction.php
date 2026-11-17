<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Localization\Loc;

/**
 * "Delete" item (TPL-D1). Visible with the remove right (ACTION_TEMPLATE_REMOVE), which is
 * resolved in a single batch per page and stored in the service row key
 * {@see ReplicationRowActions::ROW_RIGHTS} ([P1.T2]) - separately from DTO-S1.
 */
final class DeleteRowAction extends ReplicationRowAction
{
	public static function getId(): ?string
	{
		return 'delete';
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_ROW_ACTION_DELETE');
	}

	protected function isVisible(array $rawFields): bool
	{
		$rights = $rawFields[ReplicationRowActions::ROW_RIGHTS] ?? [];

		return is_array($rights) && ($rights['canRemove'] ?? false) === true;
	}
}
