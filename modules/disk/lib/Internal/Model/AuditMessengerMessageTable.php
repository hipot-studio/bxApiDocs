<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

class AuditMessengerMessageTable extends MessengerMessageTable
{
	public static function getTableName(): string
	{
		return 'b_disk_audit_messenger_message';
	}
}
