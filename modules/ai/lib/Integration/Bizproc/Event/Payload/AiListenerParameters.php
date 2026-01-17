<?php

declare(strict_types=1);

namespace Bitrix\Ai\Integration\Bizproc\Event\Payload;

use Bitrix\Bizproc\Public\Event\Payload\ListenerParameters;
use Bitrix\Bizproc\Starter\Event;

final class AiListenerParameters extends ListenerParameters
{
	public const KEY_TEMPLATE_ID = 'templateId';
	public const KEY_STARTED_BY = 'startedBy';

	public function __construct(
		public Event $event,
		public int $templateId,
		public int $startedBy = 0,
	)
	{
		parent::__construct($event);
	}

	public function toArray()
	{
		return parent::toArray() + [
				self::KEY_TEMPLATE_ID => $this->templateId,
				self::KEY_STARTED_BY => $this->startedBy,
		];
	}
}