<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\V2\Result;

class ReadAllResult extends Result
{
	public function __construct(
		public readonly int $userId,
		public readonly int $chatId,
		public readonly int $counter,
		public readonly array $excludeIds,
	)
	{
		$this->setResult(['COUNTER' => $this->counter, 'EXCLUDE_IDS' => $this->excludeIds]);
		parent::__construct();
	}
}
