<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

final class SqlLabRestLease
{
	private bool $released = false;

	/** @param string[] $locks */
	public function __construct(
		private readonly array $locks,
		private readonly SqlLabLockManager $lockManager,
	)
	{
	}

	public function release(): void
	{
		if ($this->released)
		{
			return;
		}

		$this->released = true;
		foreach (array_reverse($this->locks) as $lock)
		{
			try
			{
				$this->lockManager->release($lock);
			}
			catch (\Throwable)
			{
			}
		}
	}
}
