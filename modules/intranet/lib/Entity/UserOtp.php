<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Entity;

use Bitrix\Main\Type\DateTime;
use Bitrix\Security\Mfa\OtpType;

class UserOtp
{
	public function __construct(
		public int $userId,
		public bool $isActive,
		public ?DateTime $dateDeactivate = null,
		public bool $isInitialized = false,
		public ?OtpType $type = null,
		public bool $canSkipMandatory = false,
		public bool $canSkipMandatoryByRights = false,
		public array $initParams = [],
	) {}

	public static function initByArray(array $userOtpData): static
	{
		return new static(
			userId: $userOtpData['userId'],
			isActive: $userOtpData['isActive'],
			dateDeactivate: $userOtpData['dateDeactivate'] ?? null,
			isInitialized: $userOtpData['isInitialized'] ?? false,
			type: $userOtpData['type'] ?? null,
			canSkipMandatory: $userOtpData['canSkipMandatory'] ?? false,
			canSkipMandatoryByRights: $userOtpData['canSkipMandatoryByRights'] ?? false,
			initParams: $userOtpData['initParams'] ?? [],
		);
	}

	public function getDeactivateRemainder(): ?string
	{
		if (!$this->dateDeactivate)
		{
			return null;
		}

		$deactivateRemainder = '';
		$remainerInterval = (new DateTime())->getDiff($this->dateDeactivate);
		$now = new DateTime();

		if ($remainerInterval->d < 1)
		{
			if ($remainerInterval->h < 1)
			{
				$deactivateRemainder = FormatDate('idiff', $now->getTimestamp(), $this->dateDeactivate->getTimestamp());
			}
			else
			{
				$deactivateRemainder = FormatDate('Hdiff', $now->getTimestamp(), $this->dateDeactivate->getTimestamp());
			}
		}
		elseif ($this->dateDeactivate->getTimestamp() > $now->getTimestamp())
		{
			$deactivateRemainder = FormatDate('ddiff', $now->getTimestamp(), $this->dateDeactivate->getTimestamp());
		}

		return $deactivateRemainder;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'isActive' => $this->isActive,
			'dateDeactivate' => $this->dateDeactivate,
			'isInitialized' => $this->isInitialized,
			'type' => $this->type,
			'canSkipMandatory' => $this->canSkipMandatory,
			'canSkipMandatoryByRights' => $this->canSkipMandatoryByRights,
			'initParams' => $this->initParams,
		];
	}
}
