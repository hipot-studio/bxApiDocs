<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

/**
 * Immutable classification of an upload stop produced by {@see UploadQuotaScenario}: the wire code the
 * caller reports the shortage with, and the info-helper plus tariff CTA the client renders it with.
 */
final class UploadQuotaStopInfo
{
	public function __construct(
		private readonly string $scenario,
		private readonly string $errorCode,
		private readonly ?string $infoHelperCode,
		private readonly bool $tariffButtonAvailable,
	)
	{
	}

	public function getScenario(): string
	{
		return $this->scenario;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * The limit popup to open, or null for a stop that has none: only the cloud scenario has something
	 * to sell behind it.
	 */
	public function getInfoHelperCode(): ?string
	{
		return $this->infoHelperCode;
	}

	public function isTariffButtonAvailable(): bool
	{
		return $this->tariffButtonAvailable;
	}

	public function isCloud(): bool
	{
		return $this->scenario === UploadQuotaScenario::SCENARIO_CLOUD;
	}
}
