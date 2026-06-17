<?php

namespace Bitrix\Mail\Storage;

use Bitrix\Mail\IMessageStorage;
use Bitrix\Mail\MailMessageTable;

/**
 * @todo implement connection injection via constructor
 */
class Message implements IMessageStorage
{
	public function getMessage(int $id): \Bitrix\Mail\Item\Message
	{
		$messageData = MailMessageTable::getConsistentById($id);

		if ($messageData === null)
		{
			throw new \Bitrix\Main\SystemException(
				"Message #{$id} not found or pending deletion (UID link missing)"
			);
		}

		if (!empty($messageData['INTERNALDATE']))
		{
			$messageData['FIELD_DATE'] = $messageData['INTERNALDATE'];
		}

		return \Bitrix\Mail\Item\Message::fromArray($messageData);
	}
}