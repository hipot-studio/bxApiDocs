<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Rest\AppTable;

/**
 * Class AppLangTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppLang_Query query()
 * @method static EO_AppLang_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppLang_Result getById($id)
 * @method static EO_AppLang_Result getList(array $parameters = [])
 * @method static EO_AppLang_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppLang createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppLang_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppLang wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppLang_Collection wakeUpCollection($rows)
 */
class AppLangTable extends DataManager
{
	use \Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_rest_app_lang';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('APP_ID'))
				->configureRequired(),

			(new StringField('LANGUAGE_ID'))
				->configureRequired()
				->configureSize(2),

			(new StringField('MENU_NAME'))
				->configureSize(500),

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
}
