<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Model;

use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Data\DataManager;

/**
 * Class VibeTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Vibe_Query query()
 * @method static EO_Vibe_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Vibe_Result getById($id)
 * @method static EO_Vibe_Result getList(array $parameters = [])
 * @method static EO_Vibe_Entity getEntity()
 * @method static \Bitrix\Landing\Vibe\Model\EO_Vibe createObject($setDefaultValues = true)
 * @method static \Bitrix\Landing\Vibe\Model\EO_Vibe_Collection createCollection()
 * @method static \Bitrix\Landing\Vibe\Model\EO_Vibe wakeUpObject($row)
 * @method static \Bitrix\Landing\Vibe\Model\EO_Vibe_Collection wakeUpCollection($rows)
 */
class VibeTable extends DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName(): string
	{
		return 'b_landing_vibe';
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
			(new Fields\StringField('MODULE_ID'))
				->configureTitle('ID of module for embedding')
				->configureRequired()
			,
			(new Fields\StringField('EMBED_ID'))
				->configureTitle('ID of specific embedding')
				->configureRequired()
			,
			(new Fields\IntegerField('SITE_ID'))
				->configureTitle('ID or site')
				->configureRequired()
			,
			(new Fields\StringField('STATUS'))
				->configureTitle('ID of specific embedding')
				->configureRequired()
			,
			(new Fields\StringField('PROVIDER_CLASS'))
				->configureTitle('Class of provider for data')
				->configureRequired()
			,
		];
	}
}
