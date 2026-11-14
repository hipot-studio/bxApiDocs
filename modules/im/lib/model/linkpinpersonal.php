<?php

namespace Bitrix\Im\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserTable;

/**
 * Message pinned by a single user for themselves: AUTHOR_ID is both who pinned the message
 * and who sees the pin. CHAT_ID is denormalized from the message because every read goes by
 * the chat and owner pair.
 */
class LinkPinPersonalTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_im_link_pin_personal';
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

			(new IntegerField('AUTHOR_ID'))
				->configureRequired(),

			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValueNow(),

			'MESSAGE' => (new Reference(
				'MESSAGE',
				MessageTable::class,
				Join::on('this.MESSAGE_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),

			'CHAT' => (new Reference(
				'CHAT',
				ChatTable::class,
				Join::on('this.CHAT_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),

			'AUTHOR' => (new Reference(
				'AUTHOR',
				UserTable::class,
				Join::on('this.AUTHOR_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),
		];
	}
}
