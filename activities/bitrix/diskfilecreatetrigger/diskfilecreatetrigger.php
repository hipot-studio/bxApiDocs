<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Activity\ReturnDocumentTrait;
use Bitrix\Disk\BizProcDocument;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('CreateDocumentTrigger'))
{
	return;
}

class CBPDiskFileCreateTrigger extends CBPCreateDocumentTrigger
{
	use ReturnDocumentTrait;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties['Document'] = '';
		$this->arProperties['ReturnDocument'] = null;
	}

	protected static function preparePropertiesDialogValues(
		array $documentType,
		array $properties,
		array $currentValues,
	): array
	{
		$properties['Return'] = static::buildReturnDocumentProperties(
			self::buildDocumentComplexType($properties['Document'] ?? '')
		);

		return $properties;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return ['Document' => static::buildDocumentPropertyMap(['disk'])];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		return array_merge(
			static::validateDocumentProperty($arTestProperties),
			CBPActivity::validateProperties($arTestProperties, $user),
		);
	}

	public function checkApplyRules(
		array $rules,
		\Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters,
	): \Bitrix\Bizproc\Result
	{
		$expectedStorageId = self::getStorageIdFromDocumentProperty($this->getActivityProperty('Document'));
		$actualStorageId = (int)$parameters->get('StorageId');

		if (
			$expectedStorageId <= 0
			|| $actualStorageId <= 0
			|| $expectedStorageId !== $actualStorageId
		)
		{
			return \Bitrix\Bizproc\Result::createError(new \Bitrix\Bizproc\Error('storage mismatch'));
		}

		return \Bitrix\Bizproc\Result::createOk();
	}

	protected static function getModuleId(): string
	{
		return 'disk';
	}

	protected function getDocumentComplexType(): array
	{
		return self::buildDocumentComplexType($this->getActivityProperty('Document'))
			?? parent::getDocumentComplexType();
	}

	private static function buildDocumentComplexType(mixed $value): ?array
	{
		$type = self::resolveDocumentTypeFromProperty($value);
		if ($type === '')
		{
			return null;
		}

		return ['disk', BizProcDocument::class, $type];
	}

	private static function getStorageIdFromDocumentProperty(mixed $value): int
	{
		$type = self::resolveDocumentTypeFromProperty($value);
		if ($type === '')
		{
			return 0;
		}

		return (int)BizProcDocument::getStorageIdByType($type);
	}

	private static function resolveDocumentTypeFromProperty(mixed $value): string
	{
		$parts = explode('@', (string)$value);

		return (string)($parts[2] ?? '');
	}

	private function getActivityProperty(string $name): mixed
	{
		return array_key_exists($name, $this->arProperties)
			? $this->arProperties[$name]
			: $this->getRawProperty($name);
	}
}
