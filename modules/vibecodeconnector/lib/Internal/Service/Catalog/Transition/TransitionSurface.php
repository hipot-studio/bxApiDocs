<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Transition;

enum TransitionSurface: string
{
	case App = 'app';
	case Cabinet = 'cabinet';

	public const FIELD = 'BX_VIBE_SURFACE';
}
