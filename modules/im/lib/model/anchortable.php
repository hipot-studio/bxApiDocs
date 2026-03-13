<?php

declare(strict_types=1);

namespace Bitrix\Im\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class AnchorTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Anchor_Query query()
 * @method static EO_Anchor_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Anchor_Result getById($id)
 * @method static EO_Anchor_Result getList(array $parameters = [])
 * @method static EO_Anchor_Entity getEntity()
 * @method static \Bitrix\Im\Model\EO_Anchor createObject($setDefaultValues = true)
 * @method static \Bitrix\Im\Model\EO_Anchor_Collection createCollection()
 * @method static \Bitrix\Im\Model\EO_Anchor wakeUpObject($row)
 * @method static \Bitrix\Im\Model\EO_Anchor_Collection wakeUpCollection($rows)
 */
class AnchorTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_im_anchor';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('MESSAGE_ID'))
				->configureRequired(),

			(new IntegerField('CHAT_ID'))
				->configureRequired(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new IntegerField('FROM_USER_ID'))
				->configureRequired(),

			(new StringField('TYPE'))
				->configureRequired(),

			(new StringField('SUB_TYPE'))
				->configureNullable()
				->configureDefaultValue(null),

			(new Reference('MESSAGE', MessageTable::getEntity(), Join::on('this.MESSAGE_ID', 'ref.ID')))
				->configureJoinType(Join::TYPE_INNER),

			(new Reference('CHAT', ChatTable::getEntity(), Join::on('this.CHAT_ID', 'ref.ID')))
				->configureJoinType(Join::TYPE_INNER),
		];
	}
}