<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarResourceStorage;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

class MailboxAvatarResourceTable extends DataManager
{
	public const STATE_PENDING = AvatarResourceStorage::STATE_PENDING;
	public const STATE_ATTACHED = AvatarResourceStorage::STATE_ATTACHED;
	public const STATE_ORPHANED = AvatarResourceStorage::STATE_ORPHANED;
	public const STATE_DELETING = AvatarResourceStorage::STATE_DELETING;

	public static function getTableName(): string
	{
		return 'b_mail_mailbox_avatar_resource';
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
			(new IntegerField('FILE_ID'))
				->configureRequired()
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired()
			,
			(new StringField('STATE'))
				->configureRequired()
				->configureSize(16)
				->configureDefaultValue(self::STATE_PENDING)
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			// Place in the cleanup queue: the last attempt, or the creation while there was none.
			(new DatetimeField('LAST_ATTEMPT_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
		];
	}
}
