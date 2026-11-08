<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\Activity;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Communications of an activity - who the activity talks to and through which channel.
 *
 * The table had no ORM class at all: every reader and writer of `b_crm_act_comm` went through raw SQL. The
 * schema and its three indexes stay exactly as they are; this class only makes the existing rows reachable
 * through ORM so that the communication repository has a storage boundary to sit on.
 *
 * `ENTITY_SETTINGS` is a string here on purpose. It holds a serialized snapshot of the owner names taken at
 * write time, and both the snapshot and its serialization belong to the write path of the repository, not to
 * a field callback - `NULL` in that column is the sentinel "no snapshot yet" the legacy code relies on
 * (classes/mysql/crm_activity.php:492-508), and a decoding callback would turn it into an empty value.
 *
 * Internal: instances never leave the communication repository.
 *
 * @internal
 */
final class ActivityCommunicationTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_act_comm';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('ACTIVITY_ID'))
				->configureRequired()
			,
			(new IntegerField('OWNER_ID'))
				->configureRequired()
			,
			(new IntegerField('OWNER_TYPE_ID'))
				->configureRequired()
			,
			(new StringField('TYPE'))
				->configureSize(64)
				->configureNullable()
			,
			(new StringField('VALUE'))
				->configureSize(256)
				->configureNullable()
			,
			(new IntegerField('ENTITY_ID'))
				->configureRequired()
			,
			(new IntegerField('ENTITY_TYPE_ID'))
				->configureRequired()
			,
			(new TextField('ENTITY_SETTINGS'))
				->configureNullable()
			,
		];
	}
}
