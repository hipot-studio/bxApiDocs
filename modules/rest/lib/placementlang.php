<?php

namespace Bitrix\Rest;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class PlacementLangTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> PLACEMENT_ID int mandatory
 * <li> LANGUAGE_ID string(2) mandatory
 * <li> TITLE string(255) mandatory
 * <li> DESCRIPTION string(255) optional
 * <li> GROUP_NAME string(255) optional
 * </ul>
 *
 * @package Bitrix\Rest
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_PlacementLang_Query query()
 * @method static EO_PlacementLang_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_PlacementLang_Result getById($id)
 * @method static EO_PlacementLang_Result getList(array $parameters = [])
 * @method static EO_PlacementLang_Entity getEntity()
 * @method static \Bitrix\Rest\EO_PlacementLang createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\EO_PlacementLang_Collection createCollection()
 * @method static \Bitrix\Rest\EO_PlacementLang wakeUpObject($row)
 * @method static \Bitrix\Rest\EO_PlacementLang_Collection wakeUpCollection($rows)
 */

class PlacementLangTable extends DataManager
{
	use DeleteByFilterTrait;

	private const DELETE_BATCH_SIZE = 500;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_rest_placement_lang';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new IntegerField(
				'ID',
				[
					'primary' => true,
					'autocomplete' => true,
				]
			),
			new IntegerField(
				'PLACEMENT_ID',
				[
					'required' => true,
				]
			),
			new StringField(
				'LANGUAGE_ID',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateLanguageId'],
				]
			),
			new StringField(
				'TITLE',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateTitle'],
				]
			),
			new StringField(
				'DESCRIPTION',
				[
					'validation' => [__CLASS__, 'validateDescription'],
				]
			),
			new StringField(
				'GROUP_NAME',
				[
					'validation' => [__CLASS__, 'validateGroupName'],
				]
			),
			new Reference(
				'PLACEMENT',
				\Bitrix\Rest\PlacementLangTable::class,
				Join::on('this.PLACEMENT_ID', 'ref.ID')
			),
		];
	}

	/**
	 * Returns validators for LANGUAGE_ID field.
	 *
	 * @return array
	 */
	public static function validateLanguageId()
	{
		return [
			new LengthValidator(null, 2),
		];
	}

	/**
	 * Returns validators for TITLE field.
	 *
	 * @return array
	 */
	public static function validateTitle()
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for DESCRIPTION field.
	 *
	 * @return array
	 */
	public static function validateDescription()
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for GROUP_NAME field.
	 *
	 * @return array
	 */
	public static function validateGroupName()
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Removes all application placement language phrases.
	 *
	 * @param int $appId Application ID.
	 *
	 * @return void
	 */
	public static function deleteByApp(int $appId): void
	{
		if ($appId <= 0)
		{
			throw new Main\ArgumentException(
				'Deleting by non-positive application id is not allowed (' . static::getTableName() . ').',
				'appId'
			);
		}

		$rows = PlacementTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=APP_ID' => $appId,
			],
		])->fetchAll();

		static::deleteByPlacementIds(array_map(static fn(array $row): int => (int)$row['ID'], $rows));
	}

	/**
	 * @param int[] $placementIds
	 */
	public static function deleteByPlacementIds(array $placementIds): void
	{
		foreach (array_chunk($placementIds, self::DELETE_BATCH_SIZE) as $chunk)
		{
			static::deleteByFilter(['@PLACEMENT_ID' => $chunk]);
		}
	}

	/**
	 * Removes all placement language phrases.
	 *
	 * @param int $placementId Placement ID.
	 *
	 * @return Main\DB\Result
	 */
	public static function deleteByPlacement(int $placementId) : Main\DB\Result
	{
		$connection = Main\Application::getConnection();

		return $connection->query('DELETE FROM ' . static::getTableName() . ' WHERE PLACEMENT_ID=\'' . $placementId . '\'');
	}
}