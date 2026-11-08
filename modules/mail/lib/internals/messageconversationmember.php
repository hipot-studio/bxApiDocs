<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class MessageConversationMemberTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_mail_message_conversation_member';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('MESSAGE_ID'))
				->configurePrimary()
			,
			(new IntegerField('CONVERSATION_ID'))
				->configureRequired()
			,
			(new IntegerField('MAILBOX_ID'))
				->configureRequired()
			,
			(new DatetimeField('DATE_UPDATE'))
				->configureRequired()
			,
			(new Reference(
				'MESSAGE',
				MailMessageTable::class,
				Join::on('this.MESSAGE_ID', 'ref.ID')
					->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID'),
			))->configureJoinType(Join::TYPE_INNER),
			(new Reference(
				'CONVERSATION',
				MessageConversationTable::class,
				Join::on('this.CONVERSATION_ID', 'ref.ID')
					->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID'),
			))->configureJoinType(Join::TYPE_INNER),
			// Left: no foreign key backs the projection, so a member of an already deleted mailbox
			// must stay visible to the cleanup that is meant to find it.
			(new Reference(
				'MAILBOX',
				MailboxTable::class,
				Join::on('this.MAILBOX_ID', 'ref.ID'),
			))->configureJoinType(Join::TYPE_LEFT),
		];
	}
}
