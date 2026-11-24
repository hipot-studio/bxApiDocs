<?php

namespace Bitrix\Im\Model;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

class ScheduledMessageQueueTable extends MessengerMessageTable
{
	public static function getTableName(): string
	{
		return 'b_im_scheduled_message_queue';
	}
}
