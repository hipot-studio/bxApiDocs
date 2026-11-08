<?php

declare(strict_types=1);

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Public\Activity\ReturnDocumentTrait;
use Bitrix\Crm\Integration\BizProc\Activity\Mixins\EventOutputsTrait;
use Bitrix\Crm\Integration\BizProc\Trigger\CrmEntityTriggerTrait;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('CreateDocumentTrigger'))
{
	return;
}

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	return;
}

class CBPCrmEntityCreateTrigger extends CBPCreateDocumentTrigger
{
	use CrmEntityTriggerTrait;
	use EventOutputsTrait;
	use ReturnDocumentTrait;

	private const EVENT_INITIATOR_ID = 'Initiator';
	private const EVENT_DATE_TIME_ID = 'EventDateTime';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties['Document'] = '';
		$this->arProperties['ReturnDocument'] = null;
		$this->arProperties['IsAutomatedSolution'] = 'N';
		$this->arProperties[self::CATEGORY_ID_PROPERTY] = null;
		$this->arProperties[self::EVENT_INITIATOR_ID] = null;
		$this->arProperties[self::EVENT_DATE_TIME_ID] = null;

		$this->setPropertiesTypes([
			self::EVENT_INITIATOR_ID => ['Type' => FieldType::USER],
			self::EVENT_DATE_TIME_ID => ['Type' => FieldType::DATETIME],
		]);
	}

	public function execute(): int
	{
		parent::execute();

		$document = $this->getEventData()['Document'] ?? null;
		$initiatorUserId = $this->resolveEventInitiatorUserId();

		$this->setProperties([
			static::getReturnDocumentFieldName() => $document,
			...static::buildEventOutputs($initiatorUserId),
		]);
		$this->setPropertiesTypes(
			static::buildReturnDocumentProperties(is_array($document) ? $document : null)
		);

		return CBPActivityExecutionStatus::Closed;
	}

	public static function getTestRunResolvedReturnNames(): array
	{
		return [
			self::EVENT_INITIATOR_ID,
			self::EVENT_DATE_TIME_ID,
			static::getReturnDocumentFieldName(),
		];
	}

	public static function resolveTestRunReturnValues(array $complexDocumentId, int $userId): array
	{
		return [
			...self::buildEventOutputs($userId),
			static::getReturnDocumentFieldName() => $complexDocumentId,
		];
	}

	protected static function preparePropertiesDialogValues(
		array $documentType,
		array $properties,
		array $currentValues,
	): array
	{
		$document = static::resolveDocumentTypeFromDocument((string)($properties['Document'] ?? ''));

		$properties['Return'] = static::buildReturnDocumentProperties(
			$document,
		);

		return $properties;
	}

	public static function getConditionDocumentSourceFieldNames(): array
	{
		return ['Document'];
	}

	public function getConditionDocumentComplexType(): ?array
	{
		return static::resolveConditionDocumentType($this->getRawProperty('Document'));
	}

	public function getConditionDocumentId(TriggerParameters $parameters): ?array
	{
		$conditionType = $this->getConditionDocumentComplexType();
		$documentId = $parameters->get('Document');
		if (!static::isConditionDocumentIdOfType($documentId, $conditionType))
		{
			return null;
		}

		return array_values($documentId);
	}

	private static function resolveConditionDocumentType(mixed $document): ?array
	{
		if (!\CBPHelper::hasStringRepresentation($document))
		{
			return null;
		}

		$documentType = static::resolveDocumentTypeFromDocument((string)$document);

		return static::isComplexDocumentType($documentType) ? $documentType : null;
	}

	private static function isConditionDocumentIdOfType(mixed $documentId, ?array $conditionType): bool
	{
		return static::isComplexDocumentType($documentId)
			&& $conditionType !== null
			&& \CBPHelper::isEqualDocumentEntity($documentId, $conditionType)
		;
	}

	private static function isComplexDocumentType(mixed $document): bool
	{
		if (!is_array($document) || count($document) !== 3)
		{
			return false;
		}

		foreach ($document as $part)
		{
			if (!is_string($part) || $part === '')
			{
				return false;
			}
		}

		return true;
	}
}
