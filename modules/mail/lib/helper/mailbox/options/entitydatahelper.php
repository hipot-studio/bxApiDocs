<?php

namespace Bitrix\Mail\Helper\Mailbox\Options;

use Bitrix\Mail\Internals\MailEntityDataTable;

class EntityDataHelper extends BaseOptionsHelper
{
	public const FOLDER_SORT_MODE = 'FOLDER_SORT_MODE';
	public const FOLDER_EXPAND_STATE = 'FOLDER_EXPAND_STATE';
	public const FOLDER_CUSTOM_ORDER = 'FOLDER_CUSTOM_ORDER';

	protected static function getTableClass(): string
	{
		return MailEntityDataTable::class;
	}
}
