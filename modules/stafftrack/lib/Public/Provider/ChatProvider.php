<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Result;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;

class ChatProvider
{
	public function createDepartmentHeadChat(): Result
	{
		$result = new Result();

		$chatCreateResult = MessageService::createChatWithDepartmentHead();
		if (!$chatCreateResult->isSuccess())
		{
			return $result->addErrors($chatCreateResult->getErrors());
		}

		return $result->setData($chatCreateResult->getData());
	}
}
