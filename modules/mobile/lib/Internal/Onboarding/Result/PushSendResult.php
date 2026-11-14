<?php

namespace Bitrix\Mobile\Internal\Onboarding\Result;

use Bitrix\Main\Result;

class PushSendResult extends Result
{
	public function setPushType(?string $pushType): self
	{
		$this->data['pushType'] = $pushType;

		return $this;
	}

	public function getPushType(): ?string
	{
		return $this->data['pushType'] ?? null;
	}

	public function wasSent(): bool
	{
		return $this->isSuccess();
	}

	/**
	 * Human-readable reason of the first failure - for the log and the dev harness.
	 * Code matching belongs to getFailureCode().
	 */
	public function getFailureReason(): ?string
	{
		return $this->getError()?->getMessage();
	}

	public function getFailureCode(): int|string|null
	{
		return $this->getError()?->getCode();
	}
}
