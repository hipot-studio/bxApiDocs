<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Result;

/**
 * Class DocumentBindingTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DocumentBinding_Query query()
 * @method static EO_DocumentBinding_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DocumentBinding_Result getById($id)
 * @method static EO_DocumentBinding_Result getList(array $parameters = [])
 * @method static EO_DocumentBinding_Entity getEntity()
 * @method static \Bitrix\DocumentGenerator\Model\EO_DocumentBinding createObject($setDefaultValues = true)
 * @method static \Bitrix\DocumentGenerator\Model\EO_DocumentBinding_Collection createCollection()
 * @method static \Bitrix\DocumentGenerator\Model\EO_DocumentBinding wakeUpObject($row)
 * @method static \Bitrix\DocumentGenerator\Model\EO_DocumentBinding_Collection wakeUpCollection($rows)
 */
class DocumentBindingTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_documentgenerator_document_binding';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('DOCUMENT_ID'))
				->configureRequired(),
			(new StringField('ENTITY_NAME'))
				->configureRequired(),
			(new IntegerField('ENTITY_ID'))
				->configureRequired(),
			(new Reference(
				'DOCUMENT',
				DocumentTable::class,
				[
					'=this.DOCUMENT_ID' => 'ref.ID'
				]
			)),
		];
	}

	public static function deleteByEntityName(string $entityName): void
	{
		$list = static::getList([
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_NAME' => $entityName,
			],
		])->fetchCollection();
		foreach ($list as $binding)
		{
			$binding->delete();
		}
	}

	public static function deleteBindings(string $entityName, int $entityId): void
	{
		$list = static::getList([
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_NAME' => $entityName,
				'=ENTITY_ID' => $entityId,
			],
		])->fetchCollection();
		foreach ($list as $binding)
		{
			$binding->delete();
		}
	}

	public static function bindDocument(int $documentId, string $entityName, int $entityId): Result
	{
		$record = static::createObject();

		$record->set('DOCUMENT_ID', $documentId);
		$record->set('ENTITY_NAME', $entityName);
		$record->set('ENTITY_ID', $entityId);

		return $record->save();
	}
}
