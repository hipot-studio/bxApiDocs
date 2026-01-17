<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common;

use Bitrix\Main\EventResult;

interface Event
{
	/**
	 * @param null $sender for compatibility with \Bitrix\Main\Event
	 * @return void
	 */
	public function send($sender = null);

	/**
	 * @return EventResult[]
	 */
	public function getResults();
	public function hasResult(): bool;
	public function isCancelled(): bool;
}
