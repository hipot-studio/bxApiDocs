<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Result;

class MultiReadResult extends Result
{
	public function __construct(
		public readonly UsersCounterMap $counters,
		public readonly array $readList,
	)
	{
		$this->setResult(['COUNTERS' => $this->counters, 'VIEWED_MESSAGES' => $this->readList]);
		parent::__construct();
	}
}
