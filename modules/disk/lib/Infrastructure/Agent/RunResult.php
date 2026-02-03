<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

class RunResult
{
	/**
	 * @param bool $isRetry
	 */
	public function __construct(
		protected bool $isRetry,
	)
	{
	}

	/**
	 * @return bool
	 */
	public function getIsRetry(): bool
	{
		return $this->isRetry;
	}
}