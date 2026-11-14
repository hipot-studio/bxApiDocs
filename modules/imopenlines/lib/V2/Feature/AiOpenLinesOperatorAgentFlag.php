<?php

namespace Bitrix\ImOpenLines\V2\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class AiOpenLinesOperatorAgentFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
