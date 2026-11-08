<?php


namespace Bitrix\Tasks\Access\Rule\Traits;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Model\ChecklistModel;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

trait ChecklistTrait
{
	/**
	 * @param $params
	 * @return bool
	 */
	private function isList($params): bool
	{
		return
			is_array($params)
			&& (array_keys($params)[0] ?? null) === 0
		;
	}

	/**
	 * @param $params
	 * @param AccessibleItem $item - task or template the checklist is checked against
	 * @return ChecklistModel
	 */
	private function getModelFromParams($params, AccessibleItem $item): ChecklistModel
	{
		return $this->createModelFromParams($params)->setContext($this->resolveContext($item));
	}

	/**
	 * @param ChecklistModel $checklist
	 * @param AccessibleItem $item
	 * @return bool
	 */
	private function isChecklistBoundToItem(ChecklistModel $checklist, AccessibleItem $item): bool
	{
		$entityId = $checklist->getEntityId();

		if ($entityId > 0 && $entityId !== $item->getId())
		{
			$this->controller->addError(static::class, 'Incorrect checklist data');

			return false;
		}

		return true;
	}

	/**
	 * @param $params
	 * @return ChecklistModel
	 */
	private function createModelFromParams($params): ChecklistModel
	{
		if ($params instanceof ChecklistModel)
		{
			return $params;
		}

		if (is_array($params) && !$this->isList($params))
		{
			// a plain array reaches the rules from the transport, so it carries no trusted attributes
			return ChecklistModel::createFromRequest($params);
		}

		if ($params instanceof CheckList)
		{
			return ChecklistModel::createFromChecklist($params);
		}

		if (is_numeric($params))
		{
			return ChecklistModel::createFromId((int) $params);
		}

		return new ChecklistModel();
	}

	/**
	 * @param AccessibleItem $item
	 * @return Type
	 */
	private function resolveContext(AccessibleItem $item): Type
	{
		return ($item instanceof TemplateModel) ? Type::Template : Type::Task;
	}

	/**
	 * @param array $params
	 * @return array
	 */
	private function prepareParams(array $params): array
	{
		$res = $params;

		foreach ($params as $k => $row)
		{
			if (empty($row['MEMBERS']) || !is_array($row['MEMBERS']))
			{
				continue;
			}

			$members = [];
			foreach ($row['MEMBERS'] as $v => $member)
			{
				if (!is_array($member))
				{
					continue;
				}

				$memberKeys = array_keys($member);
				if (!is_array($member[$memberKeys[0]]))
				{
					$members[$v] = $member;
					continue;
				}

				foreach ($member as $id => $data)
				{
					$members[$id] = [
						'TYPE' => ($data['TYPE'] === 'accomplice') ? MemberTable::MEMBER_TYPE_ACCOMPLICE : MemberTable::MEMBER_TYPE_AUDITOR,
						'NAME' => $data['NAME']
					];
				}
			}
			$res[$k]['MEMBERS'] = $members;
		}

		return $res;
	}
}