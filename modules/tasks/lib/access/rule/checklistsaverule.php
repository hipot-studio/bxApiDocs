<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2021 Bitrix
 */

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\ChecklistModel;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Rule\Traits\ChecklistTrait;

class ChecklistSaveRule extends \Bitrix\Main\Access\Rule\AbstractRule
{
	use ChecklistTrait;

	private const
		ADDED = 'added',
		CHANGED = 'changed',
		DELETED = 'deleted',
		STATUS_CHANGED = 'status_changed';

	/**
	 * @param AccessibleItem|null $task
	 * @param null $params
	 * @return bool
	 */
	public function execute(AccessibleItem $task = null, $params = null): bool
	{
		if (!$task)
		{
			$this->controller->addError(static::class, 'Incorrect task');
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if (!$this->controller->check(ActionDictionary::ACTION_TASK_READ, $task, $params))
		{
			return false;
		}

		// user can edit all checklist's items
		if ($this->controller->check(ActionDictionary::ACTION_CHECKLIST_EDIT, $task, $params))
		{
			return true;
		}

		if ($task instanceof TemplateModel)
		{
			$this->controller->addError(static::class, 'Unable to works with template');
			return false;
		}

		if (!$this->isList($params))
		{
			return $this->checkSingleItem($params, $task);
		}

		// Warning! Mass check for webform format only. See \TasksTaskComponent::checkRights()
		$delta = $this->getDelta($task->getChecklist(), $params);
		// nothing changed
		if (empty($delta))
		{
			return true;
		}

		if (!array_key_exists(self::CHANGED, $delta))
		{
			return $this->controller->check(ActionDictionary::ACTION_CHECKLIST_ADD, $task, $params);
		}

		// rows of the delta come from $task->getChecklist(), so they are read from storage
		foreach ($delta[self::CHANGED] as $row)
		{
			if (!$this->controller->check(
				ActionDictionary::ACTION_CHECKLIST_EDIT,
				$task,
				ChecklistModel::createFromArray($row),
			))
			{
				$this->controller->addError(static::class, 'Access to checklist edit denied');
				return false;
			}
		}

		foreach ($delta[self::STATUS_CHANGED] ?? [] as $row)
		{
			if (!$this->controller->check(
				ActionDictionary::ACTION_CHECKLIST_TOGGLE,
				$task,
				ChecklistModel::createFromArray($row),
			))
			{
				$this->controller->addError(static::class, 'Access to checklist toggle denied');
				return false;
			}
		}

		return true;
	}

	/**
	 * A single payload names the item by identifier only, so the row answering for it is taken from the
	 * checklist of the entity: an identifier the entity does not hold has no row to answer.
	 *
	 * @param $params
	 * @param AccessibleItem $task
	 * @return bool
	 */
	private function checkSingleItem($params, AccessibleItem $task): bool
	{
		$checklist = is_array($params)
			? $this->getStoredItemModel($params, $task)
			: $this->getModelFromParams($params, $task);

		if (!$checklist)
		{
			$this->controller->addError(static::class, 'Incorrect checklist data');
			return false;
		}

		$action = $checklist->getId()
			? ActionDictionary::ACTION_CHECKLIST_EDIT
			: ActionDictionary::ACTION_CHECKLIST_ADD;

		return $this->controller->check($action, $task, $checklist);
	}

	/**
	 * @param array $params
	 * @param AccessibleItem $task
	 * @return ChecklistModel|null null when the addressed item is none of the entity's own
	 */
	private function getStoredItemModel(array $params, AccessibleItem $task): ?ChecklistModel
	{
		$itemId = (int)($params['ID'] ?? 0);
		if ($itemId <= 0)
		{
			// no item is addressed, so the payload adds one and there is no stored row to decide by
			return new ChecklistModel();
		}

		$row = $task->getChecklist()[$itemId] ?? null;

		return $row ? ChecklistModel::createFromArray($row) : null;
	}

	/**
	 * @param array $old
	 * @param array $new
	 * @return array
	 */
	private function getDelta(array $old, array $new)
	{
		$delta = [];

		$new = $this->prepareParams($new);

		$old = array_column($old, null, 'ID');
		$new = array_column($new, null, 'ID');

		if (array_key_exists('', $new))
		{
			$delta[self::ADDED] = true;
			unset($new['']);
		}

		if (empty($old))
		{
			$delta[self::ADDED] = true;
		}

		foreach ($old as $id => $row)
		{
			if (
				!array_key_exists($id, $new)
				|| $this->isUpdated($row, $new[$id])
			)
			{
				$delta[self::CHANGED][] = $row;
			}

			if (
				array_key_exists($id, $new)
				&& $this->isStatusChanged($row, $new[$id])
			)
			{
				$delta[self::STATUS_CHANGED][] = $row;
			}
		}

		foreach ($new as $id => $row)
		{
			if (!array_key_exists($id, $old))
			{
				$delta[self::ADDED] = true;
			}
		}

		return $delta;
	}

	/**
	 * @param $old
	 * @param $new
	 * @return bool
	 */
	private function isStatusChanged($old, $new): bool
	{
		$new['IS_COMPLETE'] = ((int) $new['IS_COMPLETE']) ? 'Y' : 'N';

		if ($old['IS_COMPLETE'] !== $new['IS_COMPLETE'])
		{
			return true;
		}

		return false;
	}

	/**
	 * @param $old
	 * @param $new
	 * @return bool
	 */
	private function isUpdated($old, $new): bool
	{
		$fields = [
			'PARENT_ID',
			'TITLE',
			'IS_IMPORTANT',
			'MEMBERS',
			'ATTACHMENTS'
		];

		$new['TITLE'] = \Bitrix\Main\Text\Encoding::convertEncodingToCurrent($new['TITLE']);
		if (empty($new['MEMBERS']))
		{
			$new['MEMBERS'] = [];
		}
		if (empty($new['ATTACHMENTS']))
		{
			$new['ATTACHMENTS'] = [];
		}

		$newMembers = [];
		foreach ($new['MEMBERS'] as $id => $member)
		{
			$newMembers[] = [
				'ID' => $id,
				'TYPE' => $member['TYPE'],
			];
		}

		$new['MEMBERS'] = $newMembers;

		$oldMembers = [];
		foreach ($old['MEMBERS'] as $id => $member)
		{
			$oldMembers[] = [
				'ID' => $id,
				'TYPE' => $member['TYPE'],
			];
		}

		$old['MEMBERS'] = $oldMembers;

		$new['IS_COMPLETE'] = ((int) $new['IS_COMPLETE']) ? 'Y' : 'N';
		$new['IS_IMPORTANT'] = ((int) $new['IS_IMPORTANT']) ? 'Y' : 'N';

		foreach ($fields as $field)
		{
			if ($field === 'ATTACHMENTS')
			{
				$oldAttachments = array_keys($old['ATTACHMENTS']);
				$newAttachments = array_keys($new['ATTACHMENTS']);

				if (count(array_intersect($oldAttachments, $newAttachments)) !== count($oldAttachments))
				{
					return true;
				}

				continue;
			}

			if (($old[$field] ?? null) != ($new[$field] ?? null))
			{
				return true;
			}
		}

		return false;
	}
}