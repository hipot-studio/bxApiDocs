<?php

namespace Bitrix\Translate\Controller;

/**
 * @internal
 */

interface ITimeLimit
{
	/**
	 * Start up timer.
	 *
	 * @return self
	 */
	public function startTimer();

	/**
	 * Tells true if time limit reached.
	 *
	 * @return boolean
	 */
	public function hasTimeLimitReached();
}
