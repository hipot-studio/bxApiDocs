<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Entity;

use Bitrix\Main\Type\DateTime;
use Bitrix\Security\Mfa\OtpType;

class UserOtp
{
	private const SECONDS_IN_HOUR = 3600;
	private const SECONDS_IN_DAY = 86400;

	public function __construct(
		public int $userId,
		public bool $isActive,
		public ?DateTime $dateDeactivate = null,
		public bool $isInitialized = false,
		public ?DateTime $initialDate = null,
		public ?OtpType $type = null,
		public ?bool $isMandatorySkipped = false,
		public array $initParams = [],
	) {}

	public static function initByArray(array $userOtpData): static
	{
		return new static(
			userId: $userOtpData['userId'],
			isActive: $userOtpData['isActive'],
			dateDeactivate: $userOtpData['dateDeactivate'] ?? null,
			isInitialized: $userOtpData['isInitialized'] ?? false,
			initialDate: $userOtpData['initialDate'] ?? null,
			type: $userOtpData['type'] ?? null,
			isMandatorySkipped: $userOtpData['isMandatorySkipped'] ?? false,
			initParams: $userOtpData['initParams'] ?? [],
		);
	}

	public function getDeactivateRemainder(?DateTime $now = null): ?string
	{
		if (!$this->dateDeactivate)
		{
			return null;
		}

		$nowTs = ($now ?? new DateTime())->getTimestamp();
		$deactivateTs = $this->dateDeactivate->getTimestamp();
		$secondsLeft = $deactivateTs - $nowTs;

		if ($secondsLeft <= -self::SECONDS_IN_DAY)
		{
			return '';
		}

		if ($secondsLeft >= self::SECONDS_IN_DAY)
		{
			return FormatDate('ddiff', $nowTs, $deactivateTs);
		}

		if (abs($secondsLeft) < self::SECONDS_IN_HOUR)
		{
			return FormatDate('idiff', $nowTs, $deactivateTs);
		}

		return FormatDate('Hdiff', $nowTs, $deactivateTs);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'isActive' => $this->isActive,
			'dateDeactivate' => $this->dateDeactivate,
			'isInitialized' => $this->isInitialized,
			'initialDate' => $this->initialDate,
			'type' => $this->type,
			'isMandatorySkipped' => $this->isMandatorySkipped,
			'initParams' => $this->initParams,
		];
	}
}
