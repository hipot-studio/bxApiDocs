<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\Merge;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Rest\AppTable;

/**
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppAttribute_Query query()
 * @method static EO_AppAttribute_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppAttribute_Result getById($id)
 * @method static EO_AppAttribute_Result getList(array $parameters = [])
 * @method static EO_AppAttribute_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAttribute createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAttribute_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAttribute wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAttribute_Collection wakeUpCollection($rows)
 */
class AppAttributeTable extends DataManager
{
	use AddMergeTrait;

	protected static function getMergeStrategy(): AddStrategy
	{
		return new Merge(static::getEntity(), ['APP_ID', 'TYPE', 'CODE']);
	}

	public static function getTableName(): string
	{
		return 'b_rest_app_attribute';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('APP_ID'))
				->configureRequired(),

			(new StringField('TYPE'))
				->configureRequired()
				->configureSize(1)
				->configureDefaultValue('S')
			,

			(new StringField('CODE'))
				->configureRequired()
				->configureSize(50)
			,

			(new StringField('VALUE'))
				->configureNullable()
				->configureSize(1000)
			,

			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(static function () {
					return new Main\Type\DateTime();
				}),

			(new Reference(
				'APP',
				AppTable::class,
				Join::on('this.APP_ID', 'ref.ID'),
			)),
		];
	}

	public static function deleteByAppId(int $appId): void
	{
		$connection = Main\Application::getConnection();
		$connection->queryExecute(
			sprintf(
				'DELETE FROM %s WHERE APP_ID = %d',
				static::getTableName(),
				$appId
			)
		);
	}

	/**
	 * @param string[] $codes
	 */
	public static function deleteOrphansByAppId(int $appId, string $type, array $codes): void
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		if (empty($codes))
		{
			$connection->queryExecute(
				sprintf(
					'DELETE FROM %s WHERE APP_ID = %d AND TYPE = \'%s\'',
					static::getTableName(),
					$appId,
					$helper->forSql($type),
				)
			);

			return;
		}

		$escapedCodes = implode(
			',',
			array_map(
				static fn(string $code) => "'" . $helper->forSql($code) . "'",
				$codes,
			),
		);

		$connection->queryExecute(
			sprintf(
				'DELETE FROM %s WHERE APP_ID = %d AND TYPE = \'%s\' AND CODE NOT IN (%s)',
				static::getTableName(),
				$appId,
				$helper->forSql($type),
				$escapedCodes,
			)
		);
	}
}
