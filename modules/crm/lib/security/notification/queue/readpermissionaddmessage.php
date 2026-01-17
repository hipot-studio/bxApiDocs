<?php

namespace Bitrix\Crm\Security\Notification\Queue;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

final class ReadPermissionAddMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $automatedSolutionId,
		public readonly string $automatedSolutionTitle,
		public readonly string $sectionHref,
		public readonly int $toUserId,
		public readonly int $fromUserId,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'automatedSolutionId' => $this->automatedSolutionId,
			'automatedSolutionTitle' => $this->automatedSolutionTitle,
			'sectionHref' => $this->sectionHref,
			'toUserId' => $this->toUserId,
			'fromUserId' => $this->fromUserId,
		];
	}
}
