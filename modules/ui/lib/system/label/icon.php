<?php declare(strict_types=1);

namespace Bitrix\UI\System\Label;

enum Icon: string
{
	case NONE = '';
	case CHECK = 'check';
	case ATTENTION = 'attention';
	case CROSS = 'cross';
	case QUESTION = 'question';
	case CHECK_STROKE = 'checkStroke';
	case CROSS_STROKE = 'crossStroke';
	case PROCESS_STROKE = 'processStroke';
}

