<?php

namespace Bitrix\Crm\WebForm\Debug;

use Bitrix\Crm\Service\Container;
use Exception;
use Psr\Log\LoggerInterface;

class ResultEntityDebugLogger
{
	private ?int $formId = null;
	private array $rawFields = [];
	/**
	 * @var array<array{ENTITY_TYPE: string, ENTITY_ID: int, IS_DUPLICATE: bool}>
	 */
	private array $resultEntityPack = [];
	private string $caseName = '';

	private array $additionalContext = [];

	private LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? Container::getInstance()->getLogger('Webform');
	}

	public function setFormId(?int $formId): self
	{
		$this->formId = $formId;

		return $this;
	}

	public function setRawFields(array $fields): self
	{
		$this->rawFields = $fields;

		return $this;
	}

	/**
	 * @param array<array{ENTITY_TYPE: string, ENTITY_ID: int, IS_DUPLICATE: bool}> $resultEntityPack
	 * @return $this
	 */
	public function setResultEntityPack(array $resultEntityPack): self
	{
		$this->resultEntityPack = $resultEntityPack;

		return $this;
	}

	public function setCaseName(string $caseName): self
	{
		$this->caseName = $caseName;

		return $this;
	}

	public function addAdditionalContext(string $key, mixed $value): self
	{
		$this->additionalContext[$key] = $value;

		return $this;
	}

	public function analyze(): void
	{
		$this->checkIfFilesAreNotSavedInCorrespondingEntities();
	}

	/**
	 * Reports one legally significant acceptance that the consent journal does not hold.
	 *
	 * The trail is read by support and has to stay free of the personal data of the visitor, so it
	 * carries identifiers and an error code only - never the text of the document, the submitted
	 * values, the address of the visitor or the security code of the form.
	 */
	public function logConsentNotRecorded(
		int $resultId,
		int $activityId,
		int $agreementId,
		string $errorCode,
	): void
	{
		$this->logger->error(
			"ResultEntity: Form: $this->formId. Consent of agreement $agreementId"
			. " is not recorded for result $resultId. Error: $errorCode.",
			[
				'formId' => $this->formId,
				'resultId' => $resultId,
				'activityId' => $activityId,
				'agreementId' => $agreementId,
				'errorCode' => $errorCode,
			],
		);
	}

	private function checkIfFilesAreNotSavedInCorrespondingEntities(): void
	{
		$fileFields = $this->extractFilledFileFields($this->rawFields);
		if (empty($this->resultEntityPack) || empty($fileFields))
		{
			return;
		}

		$resultEntityPackByTypeMap = [];
		foreach ($this->resultEntityPack as $entity)
		{
			$entityType = $entity['ENTITY_NAME'] ?? '';
			if (empty($entityType))
			{
				continue;
			}

			$resultEntityPackByTypeMap[$entityType] = $entity;
		}

		foreach ($fileFields as $fileField)
		{
			if (empty($fileField['entity_name']) || empty($fileField['entity_field_name']))
			{
				continue;
			}

			$correspondingEntity = $resultEntityPackByTypeMap[$fileField['entity_name']] ?? null;
			if ($correspondingEntity === null)
			{
				continue;
			}

			$entityType = $correspondingEntity['ENTITY_NAME'] ?? '';
			$entityId = $correspondingEntity['ITEM_ID'] ?? null;
			if (empty($entityType) || empty($entityId))
			{
				continue;
			}

			$entityTypeId = \CCrmOwnerType::ResolveID($entityType);
			if (!\CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
			{
				continue;
			}

			$correspondingEntityItem = Container::getInstance()
				->getFactory($entityTypeId)
				?->getItem($entityId)
			;
			if (!$correspondingEntityItem)
			{
				continue;
			}

			try
			{
				$entityFieldValue = $correspondingEntityItem->get($fileField['entity_field_name']);
			}
			catch (Exception $exception)
			{
				continue;
			}

			if (empty($entityFieldValue))
			{
				$fieldName = $fileField['name'] ?? '';
				$this->logger->error(
					"ResultEntity: Form: $this->formId. File field $fieldName is not saved in corresponding $entityType entity with $entityId. $this->caseName",
					[
						'fieldName' => $fieldName,
						'entityType' => $entityType,
						'entityId' => $entityId,
						'formId' => $this->formId,
						'fileField' => $fileField,
						'additionalContext' => $this->additionalContext,
					],
				);
			}
		}
	}

	/**
	 * @param array $fields
	 * @return array{type: string, values: array, entity_name: string}[]
	 */
	private function extractFilledFileFields(array $fields): array
	{
		return array_values(
			array_filter(
			$fields,
			static fn($field) =>
				($field['type'] ?? '') === 'file'
				&& !empty($field['values'])
			)
		);
	}
}