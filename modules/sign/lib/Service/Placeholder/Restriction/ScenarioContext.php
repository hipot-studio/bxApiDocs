<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

use Bitrix\Main\Application;
use Bitrix\Sign\Type\Document\ExternalDateCreateSourceType;
use Bitrix\Sign\Type\Document\ExternalIdSourceType;
use Bitrix\Sign\Type\Document\InitiatedByType;

/**
 * Signing scenario axes a placeholder restriction rule may depend on.
 *
 * Pure data: built by ScenarioContextFactory, never reads storage itself.
 * $isMassDocument is tri-state, null means the axis is not resolved yet.
 */
class ScenarioContext
{
	public function __construct(
		public readonly ?string $scenario = null,
		public readonly InitiatedByType $initiatedByType = InitiatedByType::COMPANY,
		public readonly ?bool $isMassDocument = null,
		public readonly ?ExternalIdSourceType $externalIdSourceType = null,
		public readonly ?ExternalDateCreateSourceType $externalDateCreateSourceType = null,
		public readonly string $region = '',
		public readonly ?string $providerCode = null,
		public readonly ?int $hcmLinkCompanyId = null,
	)
	{
	}

	public static function fromAxes(
		?string $scenario = null,
		InitiatedByType $initiatedByType = InitiatedByType::COMPANY,
		?bool $isMassDocument = null,
		?ExternalIdSourceType $externalIdSourceType = null,
		?ExternalDateCreateSourceType $externalDateCreateSourceType = null,
		?string $region = null,
		?string $providerCode = null,
		?int $hcmLinkCompanyId = null,
	): self
	{
		return new self(
			scenario: $scenario,
			initiatedByType: $initiatedByType,
			isMassDocument: $isMassDocument,
			externalIdSourceType: $externalIdSourceType,
			externalDateCreateSourceType: $externalDateCreateSourceType,
			region: $region ?? self::resolveRegion(),
			providerCode: $providerCode,
			hcmLinkCompanyId: $hcmLinkCompanyId,
		);
	}

	public static function resolveRegion(): string
	{
		return (string)Application::getInstance()->getLicense()->getRegion();
	}

	public function isMassAxisResolved(): bool
	{
		return $this->isMassDocument !== null;
	}

	public function isInitiatedByEmployee(): bool
	{
		return $this->initiatedByType->isEmployee();
	}

	public function withHcmLinkCompanyId(?int $hcmLinkCompanyId): self
	{
		return new self(
			scenario: $this->scenario,
			initiatedByType: $this->initiatedByType,
			isMassDocument: $this->isMassDocument,
			externalIdSourceType: $this->externalIdSourceType,
			externalDateCreateSourceType: $this->externalDateCreateSourceType,
			region: $this->region,
			providerCode: $this->providerCode,
			hcmLinkCompanyId: $hcmLinkCompanyId,
		);
	}
}
