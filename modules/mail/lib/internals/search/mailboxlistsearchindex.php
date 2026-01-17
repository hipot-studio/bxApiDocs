<?php

namespace Bitrix\Mail\Internals\Search;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Class MailboxListSearchIndexTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailboxListSearchIndex_Query query()
 * @method static EO_MailboxListSearchIndex_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailboxListSearchIndex_Result getById($id)
 * @method static EO_MailboxListSearchIndex_Result getList(array $parameters = [])
 * @method static EO_MailboxListSearchIndex_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Search\EO_MailboxListSearchIndex createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\Search\EO_MailboxListSearchIndex_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Search\EO_MailboxListSearchIndex wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\Search\EO_MailboxListSearchIndex_Collection wakeUpCollection($rows)
 */
class MailboxListSearchIndexTable extends DataManager
{
	use AddInsertIgnoreTrait;

	const MODULE_NAME = 'mail';

	public static function getTableName()
	{
		return 'b_mail_mailbox_list_search_index';
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
			(new TextField('SEARCH_INDEX')),
		];
	}
}
