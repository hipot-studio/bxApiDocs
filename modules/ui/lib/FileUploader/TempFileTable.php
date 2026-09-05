<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;

/**
 * Class TempFileTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TempFile_Query query()
 * @method static EO_TempFile_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TempFile_Result getById($id)
 * @method static EO_TempFile_Result getList(array $parameters = [])
 * @method static EO_TempFile_Entity getEntity()
 * @method static \Bitrix\UI\FileUploader\TempFile createObject($setDefaultValues = true)
 * @method static \Bitrix\UI\FileUploader\EO_TempFile_Collection createCollection()
 * @method static \Bitrix\UI\FileUploader\TempFile wakeUpObject($row)
 * @method static \Bitrix\UI\FileUploader\EO_TempFile_Collection wakeUpCollection($rows)
 */
class TempFileTable extends Data\DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_ui_file_uploader_temp_file';
	}

	public static function getObjectClass()
	{
		return TempFile::class;
	}

	public static function getMap()
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,

			(new Fields\StringField("GUID"))
				->configureUnique(true)
				->configureNullable(false)
				->configureDefaultValue(static function () {
					return UuidGenerator::generateV4();
				})
				->configureSize(36)
			,

			new Fields\IntegerField('FILE_ID'),

			(new Fields\StringField('FILENAME'))
				->configureRequired()
				->configureSize(255)
			,

			(new Fields\IntegerField('SIZE'))
				->configureRequired()
				->configureSize(8)
			,

			(new Fields\StringField('PATH'))
				->configureRequired()
				->configureSize(255)
			,

			(new Fields\StringField('MIMETYPE'))
				->configureRequired()
				->configureSize(255)
			,

			(new Fields\IntegerField('RECEIVED_SIZE'))
				->configureSize(8)
			,
			new Fields\IntegerField('WIDTH'),
			new Fields\IntegerField('HEIGHT'),

			new Fields\IntegerField('BUCKET_ID'),
			(new Fields\StringField('MODULE_ID'))
				->configureRequired()
				->configureSize(50)
			,

			(new Fields\StringField('CONTROLLER'))
				->configureRequired()
				->configureSize(255)
			,

			(new ArrayField('CONTROLLER_OPTIONS'))
				->configureSerializationJson()
			,

			(new Fields\BooleanField('CLOUD'))
				->configureValues(0, 1)
				->configureDefaultValue(0)
			,

			(new Fields\BooleanField('UPLOADED'))
				->configureValues(0, 1)
				->configureDefaultValue(0)
			,

			(new Fields\BooleanField('DELETED'))
				->configureValues(0, 1)
				->configureDefaultValue(0)
			,

			(new Fields\IntegerField('CREATED_BY'))
				->configureRequired()
				->configureDefaultValue(static function () {
					global $USER;
					if (is_object($USER) && method_exists($USER, 'getId'))
					{
						return (int)$USER->getId();
					}

					return 0;
				})
			,

			(new Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(static function () {
					return new DateTime();
				})
			,

			new Fields\StringField('STRATEGY'),
			new Fields\IntegerField('PART_SIZE'),
			new Fields\IntegerField('PART_COUNT'),
			new Fields\StringField('UPLOAD_ID'),

			(new Reference(
				'FILE',
				\Bitrix\Main\FileTable::class,
				Join::on('this.FILE_ID', 'ref.ID'),
				['join_type' => Join::TYPE_INNER]
			)),
		];
	}

	public static function onDelete(Event $event)
	{
		$tempFile = $event->getParameter('object');
		if (!$tempFile)
		{
			$id = $event->getParameter('primary')['ID'];
			$tempFile = self::getById($id)->fetchObject();
		}

		if ($tempFile)
		{
			$tempFile->fill();

			$deleteBFile = $tempFile->customData->get('deleteBFile') !== false;
			$tempFile->deleteContent($deleteBFile);
		}

		// Cascade-delete accepted part records (for parallel strategy). FK constraints
		// are not used in DB — the ui module follows the "cascade via ORM handler" style.
		$id = null;
		if ($tempFile && $tempFile->getId())
		{
			$id = $tempFile->getId();
		}
		else
		{
			$primary = $event->getParameter('primary');
			if (is_array($primary) && isset($primary['ID']))
			{
				$id = (int)$primary['ID'];
			}
			elseif (is_numeric($primary))
			{
				$id = (int)$primary;
			}
		}

		if ($id !== null && $id > 0)
		{
			TempFilePartTable::deleteByFilter(['=TEMP_FILE_ID' => $id]);
		}
	}

	/**
	 * Updates rows matching the filter. Returns the number of affected rows so the
	 * caller can detect lease-acquisition races (atomic UPDATE with a guard condition
	 * in WHERE — see Uploader::uploadPart finalization capture).
	 *
	 * Mirrors the shape of DeleteByFilterTrait::deleteByFilter; not exposed by stock
	 * DataManager yet.
	 *
	 * @param array|ConditionTree $filter Same shape as Query::getList filter.
	 * @param array $data Column => value map; SqlExpression is honoured.
	 * @return int Affected rows.
	 * @throws ArgumentException
	 */
	public static function updateByFilter(array|ConditionTree $filter, array $data): int
	{
		$entity = static::getEntity();
		$table = static::getTableName();
		$connection = $entity->getConnection();
		$helper = $connection->getSqlHelper();

		$where = Query::buildFilterSql($entity, $filter);
		if ($where === '')
		{
			throw new ArgumentException(
				"Updating by empty filter is not allowed ({$table}).",
				'filter'
			);
		}

		[$update] = $helper->prepareUpdate($table, $data);
		if ($update === '')
		{
			throw new ArgumentException(
				"No data to update ({$table}).",
				'data'
			);
		}

		$quotedTable = $helper->quote($table);
		$connection->queryExecute("UPDATE {$quotedTable} SET {$update} WHERE {$where}");

		static::cleanCache();

		return $connection->getAffectedRowsCount();
	}
}
