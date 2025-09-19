<?php

namespace Bitrix\Im\V2\Controller\Recent;

use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Recent\RecentAiAssistant;
use Bitrix\Im\V2\Recent\RecentError;
use Bitrix\Main\Type\DateTime;

class AiAssistant extends BaseController
{
	/**
	 * @restMethod im.v2.Recent.AiAssistant.tail
	 */
	public function tailAction(int $limit = 50, array $filter = []): ?array
	{
		if (isset($filter['lastMessageDate']))
		{
			if (!DateTime::isCorrect($filter['lastMessageDate'], \DateTimeInterface::RFC3339))
			{
				$this->addError(new RecentError(RecentError::WRONG_DATETIME_FORMAT));

				return null;
			}

			$filter['lastMessageDate'] = new DateTime($filter['lastMessageDate'], \DateTimeInterface::RFC3339);
		}

		$limit = $this->getLimit($limit);
		$recent = RecentAiAssistant::getAiAssistantChats($limit, $filter['lastMessageDate'] ?? null);

		return $this->toRestFormatWithPaginationData([$recent], $limit, $recent->count());
	}
}