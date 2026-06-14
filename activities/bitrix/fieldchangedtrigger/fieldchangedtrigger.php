<?php

use Bitrix\Bizproc\Public\Entity\Document\DocumentComplexType;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPFieldChangedTrigger extends \Bitrix\Bizproc\Activity\BaseTrigger
{
	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'Document' => '',
			'Fields' => [],
		];
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$document = $context['Properties']['Document'] ?? $context['Document'] ?? '';

		return [
			'Document' => [
				'Name' => \Bitrix\Main\Localization\Loc::getMessage('BPFCT_DOCUMENT'),
				'FieldName' => 'Document',
				'Type' => \Bitrix\Bizproc\FieldType::SELECT,
				'Multiple' => false,
				'Options' => array_column(static::getAvailableDocuments(), 'name', 'id'),
				'Required' => true,
				'AllowSelection' => false,
			],
			'Fields' => [
				'Name' => \Bitrix\Main\Localization\Loc::getMessage('BPFCT_FIELDS'),
				'FieldName' => 'Fields',
				'Type' => \Bitrix\Bizproc\FieldType::SELECT,
				'Options' => static::getTrackedFields($document),
				'Multiple' => true,
				'Required' => true,
				'AllowSelection' => false,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if (\CBPHelper::isEmptyValue($arTestProperties['Document'] ?? null))
		{
			$errors[] = [
				'code' => 'Document',
				'message' => \Bitrix\Main\Localization\Loc::getMessage('BPFCT_DOCUMENT_EMPTY'),
			];
		}

		if (\CBPHelper::isEmptyValue($arTestProperties['Fields'] ?? null))
		{
			$errors[] = [
				'code' => 'Fields',
				'message' => \Bitrix\Main\Localization\Loc::getMessage('BPFCT_FIELDS_EMPTY'),
			];
		}

		return array_merge($errors, parent::validateProperties($arTestProperties, $user));
	}

	protected static function getAvailableDocuments(): array
	{
		return [];
	}

	protected static function getTrackedFields(string $document): array
	{
		return [];
	}

	protected static function resolveDocumentTypeFromDocument(string $document): ?array
	{
		return null;
	}

	public function checkApplyRules(array $rules, \Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters): \Bitrix\Bizproc\Result
	{
		$fields = CBPHelper::flatten($this->getRawProperty('Fields'));
		if (!$fields)
		{
			return \Bitrix\Bizproc\Result::createError(new \Bitrix\Bizproc\Error('empty fields')); // todo: Loc
		}

		$conditions = [];
		foreach ($fields as $field)
		{
			$conditions[] = new \Bitrix\Bizproc\Activity\Trigger\TriggerRule(
				'Fields',
				$field,
				\Bitrix\Bizproc\Activity\Enum\Operator::Modified,
				\Bitrix\Bizproc\Activity\Enum\Joiner::Or,
			);
		}

		$result = $this->checkRules($conditions, $parameters);

		return (
			$result
				? \Bitrix\Bizproc\Result::createOk()
				: \Bitrix\Bizproc\Result::createError(new \Bitrix\Bizproc\Error('The monitored fields have not changed')) // todo: Loc
		);
	}

	protected function getDocumentComplexType(): array
	{
		$complexType = parent::getDocumentComplexType();

		$document = $this->getRawProperty('Document');
		$documentType = static::resolveDocumentTypeFromDocument(
			$document && CBPHelper::hasStringRepresentation($document) ? (string)$document : null
		);

		return $documentType ?: $complexType;
	}
}
