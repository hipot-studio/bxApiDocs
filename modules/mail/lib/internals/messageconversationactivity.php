<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

class MessageConversationActivityTable extends DataManager
{
	use DeleteByFilterTrait;

	public const ALL_SYNC_SCOPE = 'A';
	private const FOLDER_SCOPE_PREFIX = 'D:';

	public static function getTableName(): string
	{
		return 'b_mail_message_conversation_activity';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('MAILBOX_ID'))
				->configurePrimary()
			,
			(new IntegerField('CONVERSATION_ID'))
				->configurePrimary()
			,
			(new StringField('SCOPE_KEY'))
				->configurePrimary()
				->configureSize(40)
			,
			(new DatetimeField('LAST_ACTIVITY'))
				->configureRequired()
			,
		];
	}

	public static function folderScope(string $dirMd5): string
	{
		return self::FOLDER_SCOPE_PREFIX . $dirMd5;
	}
}
