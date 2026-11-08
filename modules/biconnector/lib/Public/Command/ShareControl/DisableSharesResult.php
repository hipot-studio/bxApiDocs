<?php

namespace Bitrix\BIConnector\Public\Command\ShareControl;

use Bitrix\Main\Result;

class DisableSharesResult extends Result
{
	public const ERROR_SHARE_NOT_VALID = 'share_not_valid';
	public const ERROR_DISABLE_FAILED = 'share_disable_failed';

	/** @var int[] */
	private array $disabledIds = [];

	/** @var array<int, string> share id => error code */
	private array $failed = [];

	/**
	 * @return int[] Links this call switched off.
	 */
	public function getDisabledIds(): array
	{
		return $this->disabledIds;
	}

	public function addDisabledId(int $shareId): self
	{
		$this->disabledIds[] = $shareId;

		return $this;
	}

	/**
	 * @return array<int, string> share id => error code
	 */
	public function getFailed(): array
	{
		return $this->failed;
	}

	public function addFailed(int $shareId, string $errorCode): self
	{
		$this->failed[$shareId] = $errorCode;

		return $this;
	}
}
