<?php

namespace Bitrix\Mail\Helper\Mailbox\Options;

use Bitrix\Mail\Internals\MailEntityDataTable;

class EntityDataHelper extends BaseOptionsHelper
{
	public const FOLDER_SORT_MODE = 'FOLDER_SORT_MODE';
	public const FOLDER_EXPAND_STATE = 'FOLDER_EXPAND_STATE';

	protected static function getTableClass(): string
	{
		return MailEntityDataTable::class;
	}
}
