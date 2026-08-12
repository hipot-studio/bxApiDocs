<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;

abstract class ModuleSettings
{
	protected readonly array $complexType;

	public function __construct(array $complexDocumentType)
	{
		$this->complexType = $complexDocumentType;
	}

	abstract public function isAutomationFeatureEnabled(): bool;

	abstract public function isScriptFeatureEnabled(): bool;

	abstract public function isAutomationLimited(): bool;

	abstract public function isAutomationOverLimited(): bool;

	/**
	 * @return TriggerDescriptorDto|null
	 */
	public function getCreateDocumentTrigger(): ?TriggerDescriptorDto
	{
		return null;
	}

	/**
	 * @return TriggerDescriptorDto|null
	 */
	public function getEditDocumentTrigger(): ?TriggerDescriptorDto
	{
		return null;
	}

	public function getDocumentStatusFieldName(): ?string
	{
		return null;
	}

	/**
	 * @return array<Document>
	 */
	public function getTriggerRelatedDocuments(string $triggerCode, ?Document $document = null): array
	{
		return $document ? [$document] : [];
	}

	public function onBeforeRunAutomationOnUpdate(mixed $documentId): void
	{}
}
