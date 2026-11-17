<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Column\DataProvider\ReplicationProvider;

/**
 * Base burger-menu item of a "Regular tasks" section grid row (P1.T1, TPL-D1 contract).
 *
 * ONCLICK points to the frontend applier `BX.Tasks.V2.Replication.runRowAction('{actionId}', {templateId})`;
 * it carries only the literal actionId ({@see static::getId()}) and the int templateId - no
 * user-provided strings (grid data-actions XSS surface). Native row action handling is not used
 * (grid-ajax is intercepted by the frontend), so {@see processRequest()} returns null.
 *
 * Visibility is resolved per-row in {@see isVisible()}: a hidden item returns null from getControl()
 * and is not rendered (hide, not disable).
 *
 * data-testid on each item is passed through the `dataset` key: the grid keeps unknown control
 * keys (main.ui.grid class.php compatibleActions), and the popup menu item renders `dataset` onto
 * its DOM node (main popup menu-item.js) - so it reaches the item as `data-testid`.
 */
abstract class ReplicationRowAction extends BaseAction
{
	abstract protected function isVisible(array $rawFields): bool;

	public function getControl(array $rawFields): ?array
	{
		if (!$this->isVisible($rawFields))
		{
			return null;
		}

		$this->onclick = sprintf(
			"BX.Tasks.V2.Replication.runRowAction('%s', %d)",
			static::getId(),
			$this->getTemplateId($rawFields),
		);

		$control = parent::getControl($rawFields) ?? [];
		$control['dataset'] = ['testid' => 'tasks-replication-row-action-' . static::getId()];

		return $control;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null; // handled on the frontend via runRowAction
	}

	/**
	 * Row DTO-S1 (NAME column): templateId, repeatStatus, rights.canEdit.
	 *
	 * @return array<string, mixed>
	 */
	final protected function getRow(array $rawFields): array
	{
		$row = $rawFields[ReplicationProvider::NAME_COLUMN] ?? null;

		return is_array($row) ? $row : [];
	}

	final protected function getTemplateId(array $rawFields): int
	{
		return (int)($this->getRow($rawFields)['templateId'] ?? 0);
	}

	final protected function canEdit(array $rawFields): bool
	{
		return (bool)($this->getRow($rawFields)['rights']['canEdit'] ?? false);
	}

	final protected function getRepeatStatus(array $rawFields): string
	{
		return (string)($this->getRow($rawFields)['repeatStatus'] ?? '');
	}
}
