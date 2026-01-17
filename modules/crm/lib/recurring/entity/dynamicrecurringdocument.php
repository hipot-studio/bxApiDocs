<?php

namespace Bitrix\Crm\Recurring\Entity;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Model\Dynamic\RecurringTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Result;

class DynamicRecurringDocumentTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_dynamic_recurring_document';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('ENTITY_TYPE_ID'))
				->configureRequired(),
			(new IntegerField('ENTITY_ID'))
				->configureRequired(),
			(new IntegerField('DOCUMENT_ID'))
				->configureRequired(),
			(new IntegerField('RECURRING_ITEM_ID'))
				->configureRequired(),
			(new IntegerField('EMAIL_TEMPLATE_ID'))
				->configureRequired(),
			new Reference(
				'RECURRING',
				RecurringTable::class,
				[
					'=this.ENTITY_TYPE_ID' => 'ref.ENTITY_TYPE_ID',
					'=this.RECURRING_ITEM_ID' => 'ref.ITEM_ID',
				],
			),
		];
	}

	public static function deleteByItemIdentifier(ItemIdentifier $itemIdentifier): Result
	{
		$result = new Result();

		$dbResult = self::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE_ID', $itemIdentifier->getEntityTypeId())
			->where('ENTITY_ID', $itemIdentifier->getEntityId())
			->exec()
		;

		while ($row = $dbResult->fetchObject())
		{
			$deleteResult = $row->delete();
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
			}
		}

		return $result;
	}
}
