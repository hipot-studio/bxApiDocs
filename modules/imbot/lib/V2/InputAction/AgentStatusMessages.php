<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\InputAction;

use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class AgentStatusMessages
{
	private const MODULE_ID = 'imbot';

	private const CODES = [
		'IMBOT_AGENT_ACTION_THINKING',
		'IMBOT_AGENT_ACTION_SEARCHING',
		'IMBOT_AGENT_ACTION_GENERATING',
		'IMBOT_AGENT_ACTION_ANALYZING',
		'IMBOT_AGENT_ACTION_PROCESSING',
		'IMBOT_AGENT_ACTION_TRANSLATING',
		'IMBOT_AGENT_ACTION_CONNECTING',
		'IMBOT_AGENT_ACTION_CHECKING',
		'IMBOT_AGENT_ACTION_CALCULATING',
		'IMBOT_AGENT_ACTION_READING_DOCS',
		'IMBOT_AGENT_ACTION_COMPOSING',
	];

	public static function onGetInputActionStatusMessages(): EventResult
	{
		$messages = [];
		foreach (self::CODES as $code)
		{
			$messages[$code] = Loc::getMessage($code);
		}

		return new EventResult(
			EventResult::SUCCESS,
			$messages,
			self::MODULE_ID
		);
	}
}
