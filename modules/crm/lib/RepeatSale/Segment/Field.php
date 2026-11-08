<?php

namespace Bitrix\Crm\RepeatSale\Segment;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use CCrmFieldInfoAttr;

final class Field
{
	private static ?bool $isChecked = null;
	private static array $preparedDataForFilter = [];

	/**
	 * Append effective filter fields for applying filter from URL/grid.
	 *
	 * @see \Bitrix\Crm\Tracking\UI\Filter::appendEffectiveFields()
	 */
	public function appendEffectiveFilterFields(array &$fields): void
	{
		if (!$this->check() || !Container::getInstance()->getUserPermissions()->repeatSale()->canRead())
		{
			return;
		}

		if (!in_array(Item::FIELD_NAME_REPEAT_SALE_SEGMENT_ID, $fields, true))
		{
			$fields[] = Item::FIELD_NAME_REPEAT_SALE_SEGMENT_ID;
		}
	}

	public function getInfoForFilter(): array
	{
		if (!$this->check())
		{
			return [];
		}

		return [
			Item::FIELD_NAME_REPEAT_SALE_SEGMENT_ID,
			[
				'type' => 'list',
				'partial' => true,
			],
		];
	}

	public function getPreparedDataForFilter(): array
	{
		// The filter field is `partial`, and Filter\Field::toArray() assembles such fields on every render of
		// the deal list, kanban and calendar - even when the user never opens the filter. Keep the values
		// instead of querying segments again on each assemble; the values depend on permissions, so they are
		// kept per user the same way Container::getUserPermissions() does it.
		$userId = Container::getInstance()->getContext()->getUserId();
		if (isset(self::$preparedDataForFilter[$userId]))
		{
			return self::$preparedDataForFilter[$userId];
		}

		$items = [];

		if ($this->check() && Container::getInstance()->getUserPermissions()->repeatSale()->canRead())
		{
			// Child segments (e.g. `ai_approve`) create deals too and must be selectable in the filter,
			// so all segments are included here regardless of BASE_SEGMENT_CODE.
			$segments = RepeatSaleSegmentController::getInstance()->getList([
				'select' => ['ID', 'TITLE'],
				'order' => ['TITLE' => 'ASC'],
				// 0 removes the controller's default limit of 10 rows, otherwise scenarios would be cut off
				'limit' => 0,
			]);

			foreach ($segments as $segment)
			{
				$items[$segment->getId()] = $segment->getTitle();
			}
		}

		self::$preparedDataForFilter[$userId] = [
			'params' => [
				'multiple' => 'Y',
			],
			'items' => $items,
		];

		return self::$preparedDataForFilter[$userId];
	}

	/**
	 * Drops the kept filter values. A request that changes segments has to call it: the same request
	 * may assemble the filter again (the ajax actions of the segment editor do), and the values it
	 * gets must be the ones it has just written.
	 *
	 * @see \Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController
	 */
	public static function clearPreparedDataForFilter(): void
	{
		self::$preparedDataForFilter = [];
	}

	public function getInfo(): array
	{
		if (!$this->check())
		{
			return [];
		}

		return [
			Item::FIELD_NAME_REPEAT_SALE_SEGMENT_ID => [
				'TYPE' => \Bitrix\Crm\Field::TYPE_STRING,
				'ATTRIBUTES' => [
					CCrmFieldInfoAttr::ReadOnly,
				],
			],
		];
	}

	public function getSqlInfo(int $entityTypeId): array
	{
		if (!$this->check())
		{
			return [];
		}

		$logTableAlias = 'RS_SEGMENT_LOG';

		return [
			'REPEAT_SALE_SEGMENT_ID' => [
				'FIELD' => "{$logTableAlias}.SEGMENT_ID",
				'TYPE' => 'int',
				'FROM' => 'LEFT JOIN b_crm_repeat_sale_log' . " {$logTableAlias} ON"
					. " {$logTableAlias}.ENTITY_TYPE_ID = " . $entityTypeId
					. " AND {$logTableAlias}.ENTITY_ID = L.ID",
			],
		];
	}

	public function getGridHeader(): array
	{
		if (!$this->check())
		{
			return [];
		}

		return [
			'id' => Item::FIELD_NAME_REPEAT_SALE_SEGMENT_ID,
			'name' => Loc::getMessage('CRM_TYPE_ITEM_FIELD_NAME_REPEAT_SALE_SEGMENT_ID'),
			'sort' => mb_strtolower(Item::FIELD_NAME_REPEAT_SALE_SEGMENT_ID),
			'first_order' => 'desc',
			'editable' => false,
			'class' => 'string',
			'default' => false,
		];
	}

	private function check(): bool
	{
		if (self::$isChecked === null)
		{
			$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();

			self::$isChecked = $availabilityChecker->isAvailable() && $availabilityChecker->hasPermission();
		}

		return self::$isChecked;
	}
}
