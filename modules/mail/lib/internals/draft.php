<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class DraftTable extends DataManager
{
	public const CONTEXT_MAIL = 'mail';
	public const CONTEXT_CRM = 'crm';

	public const STATUS_ACTIVE = 'active';
	public const STATUS_COMPLETED = 'completed';

	public const COMPOSE_MODE_NEW = 'new';
	public const COMPOSE_MODE_REPLY = 'reply';
	public const COMPOSE_MODE_FORWARD = 'forward';

	public static function getTableName(): string
	{
		return 'b_mail_draft';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new IntegerField('USER_ID'))->configureRequired(),
			(new EnumField('CONTEXT_TYPE'))
				->configureRequired()
				->configureValues([self::CONTEXT_MAIL, self::CONTEXT_CRM]),
			// Nullable: completion releases the attempt slot of UX_B_MAIL_DRAFT_ATTEMPT.
			(new StringField('CLIENT_ID'))->configureSize(36),
			new IntegerField('CRM_ENTITY_TYPE_ID'),
			new IntegerField('CRM_ENTITY_ID'),
			(new EnumField('COMPOSE_MODE'))
				->configureRequired()
				->configureValues([
					self::COMPOSE_MODE_NEW,
					self::COMPOSE_MODE_REPLY,
					self::COMPOSE_MODE_FORWARD,
				]),
			new IntegerField('PARENT_MESSAGE_ID'),
			new TextField('SENDER_DATA'),
			(new TextField('RECIPIENTS_DATA'))->configureRequired(),
			new TextField('TO_RECIPIENTS_SEARCH'),
			new TextField('SUBJECT'),
			new TextField('BODY'),
			(new StringField('BODY_PREVIEW'))->configureSize(200),
			(new EnumField('BODY_FORMAT'))->configureRequired()->configureValues(['html']),
			new TextField('LARGE_ATTACHMENTS_DATA'),
			(new EnumField('STATUS'))
				->configureRequired()
				->configureDefaultValue(self::STATUS_ACTIVE)
				->configureValues([self::STATUS_ACTIVE, self::STATUS_COMPLETED]),
			(new IntegerField('REVISION'))->configureRequired()->configureDefaultValue(1),
			(new DatetimeField('DATE_MODIFY'))->configureRequired(),
			(new DatetimeField('DATE_EXPIRE'))->configureRequired(),
			new DatetimeField('DATE_COMPLETED'),
		];
	}
}
