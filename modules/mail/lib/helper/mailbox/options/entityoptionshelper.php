<?php

namespace Bitrix\Mail\Helper\Mailbox\Options;

use Bitrix\Mail\Internals\MailEntityOptionsTable;

class EntityOptionsHelper extends BaseOptionsHelper
{
	protected static function getTableClass(): string
	{
		return MailEntityOptionsTable::class;
	}
}
