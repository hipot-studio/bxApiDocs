<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action\Closet;

/**
 * How often in script action is running
 */
enum AppearanceMode: int
{
	case Intro = 10; // only first step
	case Core = 20; // always, except intro todo: need?
	case Finish = 30; // finish step
	case NonFinish = 40; // always, except finish
	case Always = 100; // every step
	// todo: finish?
}