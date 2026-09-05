<?php

declare(strict_types=1);

namespace Bitrix\Ui\Public\Enum\Copilot;

enum CopilotName: string
{
	case BITRIX_GPT = 'BitrixGPT';
	case COPILOT = 'CoPilot';
	case BITRIX_GPT_AGENT = 'BitrixGPT 5.6 Agent 1M';
	case COPILOT_AGENT = 'CoPilot Agent';
}
