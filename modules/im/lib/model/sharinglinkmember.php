<?php

namespace Bitrix\Im\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;

/**
 * Which guest joined which entity (ENTITY_TYPE + ENTITY_ID mirrored from the link; a chat today)
 * through which sharing link. Guests only; operational state, not a log — rows are removed on
 * kick / entity or user deletion.
 */
class SharingLinkMemberTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_im_sharing_link_member';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('SHARING_LINK_ID'))
				->configureRequired(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new StringField('ENTITY_TYPE'))
				->configureRequired()
				->configureSize(50),

			(new StringField('ENTITY_ID'))
				->configureRequired()
				->configureSize(100),

			(new DatetimeField('DATE_CREATE'))
				->configureRequired(),

			(new BooleanField('BLOCKED'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N'),

			'SHARING_LINK' => (new Reference(
				'SHARING_LINK',
				SharingLinkTable::class,
				Join::on('this.SHARING_LINK_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),
		];
	}
}
