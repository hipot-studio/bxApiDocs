<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Message;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class AddLastActivity extends AbstractBaseMessage
{
	public function __construct(
		public readonly array $fields
	)
	{

	}
	protected function getQueueId(): QueueId
	{
		return QueueId::AddLastActivity;
	}

	public function jsonSerialize(): array
	{
		$activityDate = $this->fields['ACTIVITY_DATE'] ?? null;
		if ($activityDate instanceof DateTime)
		{
			$activityDate = $activityDate->toString();
		}

		return [
			'fields' => [...$this->fields, 'ACTIVITY_DATE' => $activityDate]
		];
	}
}
