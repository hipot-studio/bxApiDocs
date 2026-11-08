<?php
namespace Bitrix\Timeman\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Controller;

use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordCollection;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordTable;
use Bitrix\Timeman\Util\Form\Filter\Validator\NumberValidator;
use Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\ToolEnabledFilter;

class WorktimeRecord extends Controller
{
	protected function getDefaultPreFilters()
	{
		return [
			new ToolEnabledFilter(),
			...parent::getDefaultPreFilters(),
			new Scope(Scope::REST),
		];
	}

	public function getAction($id)
	{
		$readableUserIds = [];
		$checkAccess = false;
		if (!$this->hasCurrentUserAccess())
		{
			$checkAccess = true;
			$readableUserIds = $this->getUserIdsWhoseRecordCurrentUserHaveAccess();
		}

		$validator = (new NumberValidator())->configureIntegerOnly(true)->configureMin(1);
		if (!$validator->validate($id)->isSuccess())
		{
			throw new ArgumentException('id must be integer, greater than 0');
		}

		$filter = ['ID' => $id];
		if ($checkAccess)
		{
			$filter['USER_ID'] = $readableUserIds;
		}

		$record = WorktimeRecordTable::query()
			->addSelect('*')
			->setFilter($filter)
			->fetchObject();

		return $record ? $this->convertRecordFields($record) : [];
	}

	public function listAction(PageNavigation $pageNavigation, $select = [], $filter = [], $order = [])
	{
		$readableUserIds = [];
		$checkAccess = false;
		if (!$this->hasCurrentUserAccess())
		{
			$checkAccess = true;
			$readableUserIds = $this->getUserIdsWhoseRecordCurrentUserHaveAccess();
		}

		foreach ($select as $field)
		{
			if (!WorktimeRecordTable::getEntity()->hasField($field))
			{
				throw new ArgumentException('WorktimeRecord does not have field ' . htmlspecialcharsbx($field));
			}
		}
		$select = empty($select) ? ['*'] : $select;
		$order = empty($order) ? ['ID' => 'DESC'] : $order;
		if (!in_array('ID', $select, true))
		{
			$select[] = 'ID';
		}

		if ($checkAccess)
		{
			if (empty($readableUserIds))
			{
				return [];
			}

			// Nested as a whole, the requested filter cannot widen the restriction with its own LOGIC.
			$filter = [$filter];
		}

		$query = WorktimeRecordTable::query()
			->setSelect($select)
			->setFilter($filter)
			->setOffset($pageNavigation->getOffset())
			->setLimit($pageNavigation->getLimit())
			->setOrder($order)
		;
		if ($checkAccess)
		{
			// Not in the array filter: a requested condition on USER_ID would retype the restriction there.
			$query->whereIn('USER_ID', $readableUserIds);
		}

		/** @var WorktimeRecordCollection $records */
		$records = $query->exec()->fetchCollection();

		$result = [];
		foreach ($records->getAll() as $record)
		{
			$result[] = $this->convertRecordFields($record);
		}

		return new Page('WORKTIME_RECORDS', $result, function () use ($filter, $checkAccess, $readableUserIds) {
			if ($checkAccess)
			{
				// As getCount() counts, plus the restriction that the array filter does not carry.
				$row = WorktimeRecordTable::query()
					->addSelect(new ExpressionField('CNT', 'COUNT(1)'))
					->setFilter($filter)
					->whereIn('USER_ID', $readableUserIds)
					->exec()
					->fetch()
				;

				return (int)$row['CNT'];
			}

			return WorktimeRecordTable::getCount($filter);
		});
	}

	private function hasCurrentUserAccess(): bool
	{
		if ($this->getCurrentUser()->isAdmin())
		{
			return true;
		}

		$accessUsers = \CTimeMan::getAccess();
		if (count($accessUsers['READ']) <= 0)
		{
			return false;
		}

		$canEditAll = in_array('*', $accessUsers['WRITE']);
		$canReadAll = in_array('*', $accessUsers['READ']);

		if ($canEditAll || $canReadAll)
		{
			return true;
		}

		return false;
	}

	private function getUserIdsWhoseRecordCurrentUserHaveAccess(): array
	{
		$accessUsers = \CTimeMan::getAccess();

		$directUsers = \CTimeMan::getDirectAccess($this->getCurrentUser()->getId());
		if (empty($directUsers))
		{
			return $accessUsers['READ'];
		}

		return array_intersect($accessUsers['READ'], $directUsers);
	}

	private function convertRecordFields(\Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord $record)
	{
		return $this->convertKeysToCamelCase($record->collectValues());
	}
}
