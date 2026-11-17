<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content\UserField;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Grid\Task\Grid;
use Bitrix\Tasks\Grid\Task\Row\Content\UserField;
use Bitrix\Tasks\Integration\CRM\CrmInTasksFeature;
use Bitrix\Tasks\Integration\CRM\Grid\CrmItemGridDataInterface;
use Bitrix\Tasks\Integration\CRM\Grid\CrmItemGridDataProvider;
use CCrmOwnerType;

/**
 * Class Crm
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content\UserField
 */
class Crm extends UserField
{
	public function prepare(): string
	{
		if (!CrmInTasksFeature::isOn())
		{
			return $this->prepareLegacy();
		}

		$row = $this->getRowData();
		$chips = $this->renderChips($row);

		if ($chips === '')
		{
			return $this->renderEmptyCell($row);
		}

		return $this->wrap($row, $chips);
	}

	/**
	 * Pre-feature rendering used when the "CRM in tasks" toggle is off: grouped-by-type CRM links, without
	 * the filter click-zones or the inline bind button.
	 */
	private function prepareLegacy(): string
	{
		$row = $this->getRowData();

		if (empty($row['UF_CRM_TASK']))
		{
			return Loc::getMessage('TASKS_GRID_TASK_ROW_CONTENT_USER_FIELD_CRM_NOT_PRESENT') ?? '';
		}

		sort($row['UF_CRM_TASK']);

		$collection = [];
		foreach ($row['UF_CRM_TASK'] as $value)
		{
			$data = $this->resolveRenderableGridData($value);
			if ($data === null)
			{
				continue;
			}

			$collection[$data->getTypeId()][] = $this->renderLegacyItem($data);
		}

		if ($collection === [])
		{
			return '';
		}

		$gridDataProvider = new CrmItemGridDataProvider();

		$html = ['<div class="tasks-list-crm-div">'];
		foreach ($collection as $typeId => $items)
		{
			$typeTitle = htmlspecialcharsbx($gridDataProvider->resolveEntityTypeTitle($typeId));

			$html[] = '<div class="tasks-list-crm-div-wrapper">';
			$html[] = "<span class='tasks-list-crm-div-type'>{$typeTitle}:</span>";
			$html[] = implode(', ', $items);
			$html[] = '</div>';
		}
		$html[] = '</div>';

		return implode('', $html);
	}

	/**
	 * Legacy list item: a link for a readable element, the depersonalized title as plain text otherwise.
	 */
	private function renderLegacyItem(CrmItemGridDataInterface $data): string
	{
		$safeTitle = htmlspecialcharsbx($data->getDisplayTitle());
		if (!$data->isReadable())
		{
			return $safeTitle;
		}

		$safeUrl = htmlspecialcharsbx((string)$data->getUrl());

		return "<a href=\"{$safeUrl}\">{$safeTitle}</a>";
	}

	private function renderChips(array $row): string
	{
		if (empty($row['UF_CRM_TASK']) || !is_array($row['UF_CRM_TASK']))
		{
			return '';
		}

		sort($row['UF_CRM_TASK']);
		$activeSlugs = $this->getActiveFilterSlugs();

		$chips = [];
		foreach ($row['UF_CRM_TASK'] as $value)
		{
			$data = $this->resolveRenderableGridData($value);
			if ($data === null)
			{
				continue;
			}

			$chip = $data->isReadable()
				? $this->renderAccessibleChip($data, $activeSlugs)
				: $this->renderInaccessibleChip($data);

			if ($chip !== '')
			{
				$chips[] = $chip;
			}
		}

		return implode('', $chips);
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

	/**
	 * Chip for an element the current user can read: the element name is plain text, hovering the chip
	 * shows the CRM mini-card, and the chip body toggles this element in the CRM_ITEM filter (click or
	 * Enter/Space on the focusable chip). A keyboard-focusable remove button (shown only while the chip
	 * is the active filter) removes it. The filter value mirrors the entity-selector field value so a chip
	 * added by click and a chip picked in the filter dialog remove symmetrically.
	 */
	private function renderAccessibleChip(CrmItemGridDataInterface $data, array $activeSlugs): string
	{
		$typeId = $data->getTypeId();
		$id = $data->getEntityId();

		$filterValue = $this->mapSlugToSelectorValue($typeId, $id);
		if ($filterValue === null)
		{
			return '';
		}

		$title = $data->getDisplayTitle();
		$safeTitle = htmlspecialcharsbx($title);
		$safeFilterValue = htmlspecialcharsbx($filterValue);
		$isActive = in_array($data->getValue(), $activeSlugs, true);
		$activeClass = $isActive ? ' tasks-grid-filter-active' : '';

		$icon = $this->renderIcon($data->getAbbreviation());

		$filterLabel = htmlspecialcharsbx((string)Loc::getMessage(
			'TASKS_GRID_TASK_ROW_CONTENT_USER_FIELD_CRM_FILTER_ADD',
			['#NAME#' => $title],
		));
		$removeLabel = htmlspecialcharsbx((string)Loc::getMessage(
			'TASKS_GRID_TASK_ROW_CONTENT_USER_FIELD_CRM_FILTER_REMOVE',
			['#NAME#' => $title],
		));

		// The chip body carries the filter value/label and drives the toggle-filter path (click or Enter/Space
		// on the focusable chip); hovering the chip shows the CRM mini-card, bound on the frontend via the
		// data-crm-mini-card-* attributes. The remove button is a real focusable button, revealed by CSS only
		// while the chip is the active filter.
		return
			'<span class="tasks-grid-username' . $activeClass . '" role="button" tabindex="0"'
			. " data-crm-filter-value=\"{$safeFilterValue}\" data-crm-filter-label=\"{$safeTitle}\""
			. " data-crm-mini-card-type-id=\"{$typeId}\" data-crm-mini-card-id=\"{$id}\""
			. " aria-label=\"{$filterLabel}\">"
			. $icon
			. "<span class=\"tasks-list-crm-item__name\">{$safeTitle}</span>"
			. '<button type="button" class="tasks-grid-filter-remove" data-crm-filter-remove="1"'
			. " aria-label=\"{$removeLabel}\" title=\"{$removeLabel}\"></button>"
			. '</span>'
		;
	}

	/**
	 * Chip for an element the current user cannot read: only the depersonalized title, and the chip is
	 * inert -- no card link, no filter click-zone, no remove control -- and visually muted.
	 */
	private function renderInaccessibleChip(CrmItemGridDataInterface $data): string
	{
		return
			'<span class="tasks-grid-username tasks-list-crm-item--locked">'
			. $this->renderIcon($data->getAbbreviation())
			. '<span class="tasks-list-crm-item__name">'
			. htmlspecialcharsbx($data->getDisplayTitle())
			. '</span>'
			. '</span>'
		;
	}

	private function renderIcon(string $type): string
	{
		$iconType = $this->resolveCrmIconType($type);

		return $iconType === ''
			? ''
			: '<i class="tasks-list-crm-item__icon --' . $iconType . '" aria-hidden="true"></i>';
	}

	/**
	 * Maps a stored CRM slug ({ABBR}_{id}) to the entity-selector filter value, i.e. the exact string the
	 * CRM_ITEM filter field stores when the element is picked through the dialog (addEntityIdToResult=Y):
	 * `["deal","15"]` for a regular type, `["dynamic_multiple","{typeId}:{itemId}"]` for a smart process.
	 * The inverse is {@see mapSelectorValueToSlug()}. Returns null when the type cannot be resolved.
	 */
	private function mapSlugToSelectorValue(int $typeId, int $id): ?string
	{
		if ($typeId === CCrmOwnerType::Undefined)
		{
			return null;
		}

		if (CCrmOwnerType::isPossibleDynamicTypeId($typeId))
		{
			return Json::encode(['dynamic_multiple', $typeId . ':' . $id]);
		}

		$entityId = mb_strtolower(CCrmOwnerType::ResolveName($typeId));
		if ($entityId === '')
		{
			return null;
		}

		return Json::encode([$entityId, (string)$id]);
	}

	private function wrap(array $row, string $chips): string
	{
		$addButton = empty($row['ACTION']['EDIT']) ? '' : $this->renderBindAddButton((int)($row['ID'] ?? 0));

		return '<div class="main-grid-tags tasks-list-crm">' . $chips . $addButton . '</div>';
	}

	private function renderEmptyCell(array $row): string
	{
		if (empty($row['ACTION']['EDIT']))
		{
			return Loc::getMessage('TASKS_GRID_TASK_ROW_CONTENT_USER_FIELD_CRM_NOT_PRESENT') ?? '';
		}

		return $this->wrap($row, '');
	}

	private function renderBindAddButton(int $taskId): string
	{
		$title = htmlspecialcharsbx(Loc::getMessage('TASKS_GRID_TASK_ROW_CONTENT_USER_FIELD_CRM_BIND_ADD') ?? '');

		// Inline bind button; a real button so it is keyboard-focusable and named. The frontend
		// delegates a click on [data-crm-bind-add].
		return
			'<button type="button" class="main-grid-tag-add" data-crm-bind-add="1"'
			. " data-task-id=\"{$taskId}\" aria-label=\"{$title}\" title=\"{$title}\"></button>"
		;
	}

	private function resolveCrmIconType(string $abbr): string
	{
		return match ($abbr)
		{
			'D' => 'deal',
			'C' => 'contact',
			'CO' => 'company',
			'L' => 'lead',
			'SI' => 'invoice',
			default => str_starts_with($abbr, 'T') ? 'dynamic' : '',
		};
	}

	/**
	 * Concrete CRM slugs of the active "CRM element" filter, used to highlight matching chips. The filter
	 * field delivers either the stored slug (cell click-filter: `D_15`) or the entity-selector JSON
	 * `["{entityId}","{id}"]` (ADD_ENTITY_ID_TO_RESULT); both are reduced to the slug. Synthetic tokens
	 * (`ALL_*`, `NONE`) select a type/absence rather than a specific element and are ignored here.
	 * Mirrors the value normalization in TaskFilterBuilder::buildCrmItemFilter().
	 *
	 * @return string[]
	 */
	private function getActiveFilterSlugs(): array
	{
		$raw = $this->getFilterField('CRM_ITEM');
		if ($raw === null || $raw === '')
		{
			return [];
		}

		// renderChips() calls this for every grid row, but the raw filter value is invariant within a
		// request; memoize the resolved slugs per raw value so the mapping runs once, not once per row.
		static $cache = [];
		$cacheKey = serialize($raw);
		if (isset($cache[$cacheKey]))
		{
			return $cache[$cacheKey];
		}

		$slugs = [];
		foreach ((is_array($raw) ? $raw : [$raw]) as $value)
		{
			[$entityId, $token] = $this->parseFilterValue((string)$value);

			if ($token === 'NONE' || str_starts_with($token, 'ALL_'))
			{
				continue;
			}

			$slug = $entityId === null ? $token : $this->mapSelectorValueToSlug($entityId, $token);
			if ($slug !== null && $slug !== '')
			{
				$slugs[] = $slug;
			}
		}

		$cache[$cacheKey] = $slugs;

		return $slugs;
	}

	/**
	 * @return array{0: ?string, 1: string} [entityId|null, token]; entityId is set only for the JSON form.
	 */
	private function parseFilterValue(string $value): array
	{
		try
		{
			$decoded = Json::decode($value);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return [null, $value];
		}

		if (is_array($decoded) && isset($decoded[0], $decoded[1]))
		{
			return [(string)$decoded[0], (string)$decoded[1]];
		}

		return [null, $value];
	}

	private function mapSelectorValueToSlug(string $entityId, string $token): ?string
	{
		$gridDataProvider = new CrmItemGridDataProvider();

		$typeId = CCrmOwnerType::ResolveID(mb_strtoupper($entityId));
		if ($typeId !== CCrmOwnerType::Undefined)
		{
			return $gridDataProvider->mapToSlug($typeId, (int)$token);
		}

		if (str_contains($token, ':'))
		{
			[$dynamicTypeId, $itemId] = explode(':', $token, 2);

			return $gridDataProvider->mapToSlug((int)$dynamicTypeId, (int)$itemId);
		}

		return null;
	}
}
