<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action\Closet;

/**
 * Is actions can run sequentially or have special requirements
 */
enum MakingStatus: int
{
	case Filming = 10; // can run next action
	case EndEpisode = 20; // need to finish shooting and wait next event
}