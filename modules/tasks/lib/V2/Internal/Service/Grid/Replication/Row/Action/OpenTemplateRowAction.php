<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Localization\Loc;

/**
 * "Open template" item (TPL-D1). Visible to anyone who can see the row, including read-only (AC-S-005).
 */
final class OpenTemplateRowAction extends ReplicationRowAction
{
	public static function getId(): ?string
	{
		return 'openTemplate';
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_ROW_ACTION_OPEN_TEMPLATE');
	}

	protected function isVisible(array $rawFields): bool
	{
		return true;
	}
}
