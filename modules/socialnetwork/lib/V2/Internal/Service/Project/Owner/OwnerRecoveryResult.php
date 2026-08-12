<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project\Owner;

use Bitrix\Main\Result;

class OwnerRecoveryResult extends Result
{
	public function getStatus(): ?OwnerRecoveryStatus
	{
		return $this->data['status'] ?? null;
	}

	public function setStatus(OwnerRecoveryStatus $status): self
	{
		$this->data['status'] = $status;

		return $this;
	}

	public function getOwnerId(): ?int
	{
		$ownerId = $this->data['ownerId'] ?? null;

		return is_int($ownerId) && $ownerId > 0 ? $ownerId : null;
	}

	public function setOwnerId(int $ownerId): self
	{
		$this->data['ownerId'] = $ownerId;

		return $this;
	}
}
