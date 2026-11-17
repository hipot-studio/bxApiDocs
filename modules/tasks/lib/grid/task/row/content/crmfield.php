<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Grid\Task\Grid;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\Integration\CRM\Grid\CrmItemGridDataInterface;
use Bitrix\Tasks\Integration\CRM\Grid\CrmItemGridDataProvider;

/**
 * Class CrmField
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content\
 */
class CrmField extends Content
{
	/**
	 * @return string
	 */
	public function prepare(): string
	{
		$row = $this->getRowData();

		if (empty($row['UF_CRM_TASK']))
		{
			return Loc::getMessage('TASKS_GRID_TASK_ROW_CONTENT_CRM_FIELD_NOT_PRESENT') ?? '';
		}

		$items = [];
		foreach ($row['UF_CRM_TASK'] as $value)
		{
			$data = $this->resolveRenderableGridData($value);
			if ($data === null || $data->getAbbreviation() !== $this->getParameter('CRM_FIELD_ID'))
			{
				continue;
			}

			$items[] = $this->renderItem($data);
		}

		return $items === []
			? ''
			: '<div class="tasks-list-crm-div">' . implode(', ', $items) . '</div>'
		;
	}

	/**
	 * A link for a readable element, the depersonalized title as plain text otherwise.
	 */
	private function renderItem(CrmItemGridDataInterface $data): string
	{
		$safeTitle = htmlspecialcharsbx($data->getDisplayTitle());
		if (!$data->isReadable())
		{
			return $safeTitle;
		}

		$safeUrl = htmlspecialcharsbx((string)$data->getUrl());

		return "<a href=\"{$safeUrl}\">{$safeTitle}</a>";
	}

	/**
	 * The safe view of one stored UF_CRM_TASK value, taken from the page-wide map the grid built before
	 * rendering. Null means the cell shows nothing at all: the element is deleted, or CRM does not support
	 * the value. A supported value the map does not carry -- a cell built outside Grid::prepareRows() --
	 * degrades to hidden, because a missing record is no permission to show the real title.
	 */
	private function resolveRenderableGridData(mixed $value): ?CrmItemGridDataInterface
	{
		$map = (array)$this->getParameter(Grid::CRM_ITEM_GRID_DATA_BY_VALUE);
		$data = is_string($value) ? ($map[$value] ?? null) : null;

		if ($data instanceof CrmItemGridDataInterface)
		{
			return $data->isNotFound() ? null : $data;
		}

		return (new CrmItemGridDataProvider())->makeHiddenData($value);
	}
}
