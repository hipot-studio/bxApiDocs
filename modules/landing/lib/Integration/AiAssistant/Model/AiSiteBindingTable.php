<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Class AiSiteBindingTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AiSiteBinding_Query query()
 * @method static EO_AiSiteBinding_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AiSiteBinding_Result getById($id)
 * @method static EO_AiSiteBinding_Result getList(array $parameters = [])
 * @method static EO_AiSiteBinding_Entity getEntity()
 * @method static \Bitrix\Landing\Integration\AiAssistant\Model\EO_AiSiteBinding createObject($setDefaultValues = true)
 * @method static \Bitrix\Landing\Integration\AiAssistant\Model\EO_AiSiteBinding_Collection createCollection()
 * @method static \Bitrix\Landing\Integration\AiAssistant\Model\EO_AiSiteBinding wakeUpObject($row)
 * @method static \Bitrix\Landing\Integration\AiAssistant\Model\EO_AiSiteBinding_Collection wakeUpCollection($rows)
 */
class AiSiteBindingTable extends DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName(): string
	{
		return 'b_landing_ai_site_binding';
	}

	/**
	 * @inheritdoc
	 */
	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new Fields\IntegerField('USER_ID'))
				->configureRequired()
			,
			(new Fields\IntegerField('SITE_ID'))
				->configureNullable()
				->addSaveDataModifier([self::class, 'normalizeNullableId'])
				->addFetchDataModifier([self::class, 'normalizeNullableId'])
			,
			(new Fields\IntegerField('GENERATION_ID'))
				->configureNullable()
				->addSaveDataModifier([self::class, 'normalizeNullableId'])
				->addFetchDataModifier([self::class, 'normalizeNullableId'])
			,
			(new Fields\IntegerField('MODERATION_BLOCK_COUNT'))
				->configureDefaultValue(0)
			,
			(new Fields\DatetimeField('DATE_CREATE'))
				->configureNullable()
			,
			(new Fields\DatetimeField('DATE_MODIFY'))
				->configureNullable()
			,
		];
	}

	public static function normalizeNullableId(mixed $value, mixed $data = null): ?int
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		$value = (int)$value;

		return $value > 0 ? $value : null;
	}
}
