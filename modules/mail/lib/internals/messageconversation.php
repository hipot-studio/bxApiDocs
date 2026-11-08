<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\MailboxTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\OneToMany;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class MessageConversationTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_mail_message_conversation';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('MAILBOX_ID'))
				->configureRequired()
			,
			(new DatetimeField('DATE_CREATE'))
				->configureRequired()
			,
			(new IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(1)
			,
			// Left: no foreign key backs the projection, so a conversation of an already deleted
			// mailbox must stay visible to the cleanup that is meant to find it.
			(new Reference(
				'MAILBOX',
				MailboxTable::class,
				Join::on('this.MAILBOX_ID', 'ref.ID'),
			))->configureJoinType(Join::TYPE_LEFT),
			new OneToMany('MEMBERS', MessageConversationMemberTable::class, 'CONVERSATION'),
		];
	}
}
