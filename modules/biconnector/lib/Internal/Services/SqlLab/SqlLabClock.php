<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

class SqlLabClock
{
	public function now(): int
	{
		return time();
	}

	public function microtime(): float
	{
		return microtime(true);
	}
}
