<?php

declare(strict_types=1);

namespace Bitrix\Ui\Public\Enum\Copilot;

enum CopilotName: string
{
	case BITRIX_GPT = 'BitrixGPT';
	case COPILOT = 'CoPilot';
	case BITRIX_GPT_AGENT = 'BitrixGPT 5.5 Agent';
	case COPILOT_AGENT = 'CoPilot Agent';
}
