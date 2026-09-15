<?php

declare(strict_types=1);

namespace Bitrix\Ai\Integration\Bizproc\Event\Payload;

use Bitrix\Bizproc\Public\Event\Payload\ListenerParameters;
use Bitrix\Bizproc\Starter\Event;

final class AiListenerParameters extends ListenerParameters
{
	public const KEY_TEMPLATE_ID = 'templateId';
	public const KEY_STARTED_BY = 'startedBy';
	public const KEY_AGENT_TEMPLATE_ID = 'agentTemplateId';
	public const KEY_AGENT_INSTANCE_ID = 'agentInstanceId';
	public const KEY_RESTART_RUN_ID = 'restartRunId';
	public const KEY_PREVIOUS_RUN_ID = 'previousRunId';
	public const KEY_INITIATOR_ID = 'initiatorId';
	public const KEY_TIMESTAMP = 'timestamp';
	public const KEY_RUN_TYPE = 'runType';

	public const RUN_TYPE_INITIAL = 'initial';
	public const RUN_TYPE_RESTART = 'restart';

	public function __construct(
		public Event $event,
		public int $templateId,
		public int $startedBy = 0,
		public string $agentInstanceId = '',
		public string $restartRunId = '',
		public string $previousRunId = '',
		public int $initiatorId = 0,
		public int $timestamp = 0,
		public string $runType = self::RUN_TYPE_INITIAL,
	)
	{
		parent::__construct($event);
	}

	public function toArray()
	{
		$result = parent::toArray() + [
			self::KEY_TEMPLATE_ID => $this->templateId,
			self::KEY_STARTED_BY => $this->startedBy,
		];

		if ($this->runType === self::RUN_TYPE_RESTART)
		{
			$result += [
				self::KEY_AGENT_TEMPLATE_ID => $this->templateId,
				self::KEY_AGENT_INSTANCE_ID => $this->agentInstanceId,
				self::KEY_RESTART_RUN_ID => $this->restartRunId,
				self::KEY_PREVIOUS_RUN_ID => $this->previousRunId,
				self::KEY_INITIATOR_ID => $this->initiatorId,
				self::KEY_TIMESTAMP => $this->timestamp,
				self::KEY_RUN_TYPE => $this->runType,
			];
		}

		return $result;
	}
}
