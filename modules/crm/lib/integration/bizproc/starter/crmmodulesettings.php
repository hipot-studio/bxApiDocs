<?php

namespace Bitrix\Crm\Integration\BizProc\Starter;

use Bitrix\Bizproc\Starter\Document;
use Bitrix\Bizproc\Starter\ModuleSettings;
use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Crm\Automation\Factory;
use Bitrix\Crm\Automation\Trigger\BaseTrigger;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\Dto\TriggerBindingDocumentsDto;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\TriggerBindingDocumentsTrait;
use CCrmOwnerType;

if (
	!\Bitrix\Main\Loader::includeModule('bizproc')
	|| !class_exists(ModuleSettings::class)
)
{
	return;
}

final class CrmModuleSettings extends ModuleSettings
{
	use TriggerBindingDocumentsTrait;

	private const CREATE_DOCUMENT_TRIGGER = 'CrmEntityCreateTrigger';

	private int $entityTypeId;

	public function __construct(array $complexDocumentType)
	{
		parent::__construct($complexDocumentType);

		[, , $documentType] = $this->complexType;
		$this->entityTypeId = \CCrmOwnerType::ResolveID($documentType);
	}

	public function isAutomationFeatureEnabled(): bool
	{
		return Factory::isAutomationAvailable($this->entityTypeId);
	}

	public function isScriptFeatureEnabled(): bool
	{
		return Factory::isScriptAvailable($this->entityTypeId);
	}

	public function isAutomationLimited(): bool
	{
		return Factory::isAutomationLimited($this->entityTypeId);
	}

	public function isAutomationOverLimited(): bool
	{
		return Factory::isOverLimited($this->entityTypeId);
	}

	public function getCreateDocumentTrigger(): ?TriggerDescriptorDto
	{
		$preset = $this->resolveCreateDocumentTriggerPreset();
		if ($preset === null)
		{
			return null;
		}

		return new TriggerDescriptorDto(
			triggerType: self::CREATE_DOCUMENT_TRIGGER,
			title: is_string($preset['NAME'] ?? null) ? $preset['NAME'] : null,
			icon: $preset['NODE_ICON'],
			properties: $this->getCreateDocumentTriggerProperties($preset),
			presetId: $preset['ID'],
		);
	}

	public function getDocumentStatusFieldName(): string
	{
		if (in_array(
			$this->entityTypeId,
			[CCrmOwnerType::Lead, CCrmOwnerType::Quote, CCrmOwnerType::Order],
			true
		))
		{
			return 'STATUS_ID';
		}

		return 'STAGE_ID';
	}

	/**
	 * @return array<Document>
	 */
	public function getTriggerRelatedDocuments(string $triggerCode, ?Document $document = null): array
	{
		if (!$document || !$document->complexType)
		{
			return [];
		}

		/** @var BaseTrigger $trigger */
		$trigger = \CCrmDocument::getTriggerByCode($triggerCode, $document->complexType);
		if (!$trigger)
		{
			return [];
		}

		[$entityTypeId, $entityId] = \CCrmBizProcHelper::resolveEntityId($document->complexId);

		$bindingDocuments = self::getBindingDocuments(
			new TriggerBindingDocumentsDto($entityTypeId, $entityId, $triggerCode),
		);

		$documents = [];
		foreach ($bindingDocuments as $binding)
		{
			[$bindingEntityTypeId, $bindingEntityId] = $binding;
			$complexId = \CCrmBizProcHelper::resolveDocumentId(...$binding);
			if ($complexId && $trigger::isSupported($bindingEntityTypeId))
			{
				$documents[] = new Document($complexId);
			}
		}

		return $documents;
	}

	public function onBeforeRunAutomationOnUpdate(mixed $documentId): void
	{
		parent::onBeforeRunAutomationOnUpdate($documentId);

		[, $entityId] = \CCrmBizProcHelper::resolveEntityIdByDocumentId($documentId);

		Factory::doAutocompleteActivities($this->entityTypeId, $entityId);
	}

	private function resolveCreateDocumentTriggerPreset(): ?array
	{
		if (!\CBPRuntime::getRuntime()->includeActivityFile(mb_strtolower(self::CREATE_DOCUMENT_TRIGGER)))
		{
			return null;
		}

		return \CBPCrmEntityCreateTrigger::getPresetByComplexDocumentType($this->complexType);
	}

	private function getCreateDocumentTriggerProperties(?array $preset): array
	{
		$properties = is_array($preset['PROPERTIES'] ?? null) ? $preset['PROPERTIES'] : [];
		$properties['Document'] = implode('@', $this->complexType);

		return $properties;
	}
}
