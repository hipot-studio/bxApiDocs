<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

/**
 * How the render mode of a unified link is chosen. Internal decision: it never reaches responses,
 * links or storage, so the layers below the controller never learn the request parameter behind it.
 */
enum ModeSelection
{
	case Auto;
	case KeepRequested;
}
