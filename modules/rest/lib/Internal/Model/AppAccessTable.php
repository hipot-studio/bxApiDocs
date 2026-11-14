<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Rest\AppTable;

/**
 * Class AppAccessTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppAccess_Query query()
 * @method static EO_AppAccess_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppAccess_Result getById($id)
 * @method static EO_AppAccess_Result getList(array $parameters = [])
 * @method static EO_AppAccess_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAccess createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAccess_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAccess wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppAccess_Collection wakeUpCollection($rows)
 */
class AppAccessTable extends DataManager
{
	use AddInsertIgnoreTrait;
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_rest_app_access';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('APP_ID'))
				->configureRequired(),

			(new StringField('ACCESS_CODE'))
				->configureRequired()
				->addValidator(new LengthValidator(1, 100)),

			(new Reference(
				'APP',
				AppTable::class,
				Join::on('this.APP_ID', 'ref.ID'),
			)),
		];
	}

	public static function deleteByAppId(int $appId): void
	{
		static::deleteByFilter(['=APP_ID' => $appId]);
	}

	/**
	 * @param string[] $codes
	 */
	public static function deleteExceptCodes(int $appId, array $codes): void
	{
		if (empty($codes))
		{
			static::deleteByAppId($appId);

			return;
		}

		static::deleteByFilter(
			(new ConditionTree())
				->where('APP_ID', $appId)
				->whereNotIn('ACCESS_CODE', $codes)
		);
	}
}
