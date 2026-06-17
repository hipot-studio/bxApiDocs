<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Recent;

use Bitrix\Tasks\V2\Internal\Async\AbstractBaseMessage;
use Bitrix\Tasks\V2\Internal\Async\QueueId;

class UpdateTemplateRecentMessage extends AbstractBaseMessage
{
	public const ACTION_ADD = 'add';
	public const ACTION_REMOVE = 'remove';

	public function __construct(
		public int $userId,
		public int $templateId,
		public string $action,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'userId' => $this->userId,
			'templateId' => $this->templateId,
			'action' => $this->action,
		];
	}

	protected function getQueueId(): QueueId
	{
		return QueueId::TemplateRecent;
	}
}
