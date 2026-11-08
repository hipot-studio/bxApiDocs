<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\DocumentGenerator\Body\PlainText;
use Bitrix\DocumentGenerator\DataProvider\ArrayDataProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class TitlePattern
{
	public const MAX_LENGTH = 255;

	public const ERROR_CODE = 'TITLE_PATTERN_INVALID';

	public const GROUP_SYSTEM = 'system';
	public const GROUP_DOCUMENT = 'document';

	private const SYSTEM_FIELD_TITLE_MESSAGES = [
		'DocumentNumber' => 'DOCGEN_TITLE_PATTERN_ITEM_DOCUMENT_NUMBER',
		'DocumentCreateTime' => 'DOCGEN_TITLE_PATTERN_ITEM_DOCUMENT_CREATE_TIME',
	];

	private const DOCUMENT_NUMBER_ALIASES = [
		'documentnumber',
		'document.document_number',
	];

	private const DOCUMENT_CREATE_TIME_ALIASES = [
		'documentcreatetime',
		'document.document_create_time',
	];

	private const DOCUMENT_TITLE_ALIASES = [
		'documenttitle',
		'document.document_title',
	];

	private string $pattern;
	private array $allowedFields = [];

	public function __construct(string $pattern)
	{
		$this->pattern = $pattern;
	}

	public function getFieldNames(): array
	{
		return (new PlainText($this->pattern))->getFieldNames();
	}

	public function getSystemFieldNames(): array
	{
		$fieldNames = [];
		foreach ($this->getFieldNames() as $fieldName)
		{
			$fieldNameKey = mb_strtolower($fieldName);
			if (in_array($fieldNameKey, self::DOCUMENT_NUMBER_ALIASES, true))
			{
				$fieldNames[] = 'DocumentNumber';
				$fieldNames[] = 'DOCUMENT.DOCUMENT_NUMBER';
			}
			elseif (in_array($fieldNameKey, self::DOCUMENT_CREATE_TIME_ALIASES, true))
			{
				$fieldNames[] = 'DocumentCreateTime';
				$fieldNames[] = 'DOCUMENT.DOCUMENT_CREATE_TIME';
			}
		}

		return array_values(array_unique($fieldNames));
	}

	public function getCatalog(Template $template, array $providerClassNames): Result
	{
		$items = [];
		foreach ($this->getAllowedFields($template, $providerClassNames) as $fieldName => $field)
		{
			$title = (string)($field['TITLE'] ?? '');
			$items[] = [
				'id' => $fieldName,
				'title' => $title === '' ? $fieldName : $title,
				'placeholder' => '{' . $fieldName . '}',
			];
		}

		return (new Result())->setData([
			'groups' => [
				[
					'id' => self::GROUP_SYSTEM,
					'title' => Loc::getMessage('DOCGEN_TITLE_PATTERN_GROUP_SYSTEM'),
					'items' => $this->getSystemItems(),
				],
				[
					'id' => self::GROUP_DOCUMENT,
					'title' => Loc::getMessage('DOCGEN_TITLE_PATTERN_GROUP_DOCUMENT'),
					'items' => $items,
				],
			],
		]);
	}

	public function validate(Template $template, array $providerClassNames): Result
	{
		$result = new Result();
		if (mb_strlen($this->pattern) > self::MAX_LENGTH)
		{
			return $this->addError($result, 'Pattern is longer than 255 characters');
		}

		if ($this->hasRepeatedBlockPlaceholder())
		{
			return $this->addError($result, 'Repeated block placeholders are not supported');
		}

		$allowedFields = $this->getAllowedFields($template, $providerClassNames);

		foreach ($this->getFieldNames() as $fieldName)
		{
			$fieldNameKey = mb_strtolower($fieldName);
			if ($this->isDocumentSourceBranch($fieldNameKey))
			{
				return $this->addError($result, 'Document source placeholders are not supported', $fieldName);
			}

			if (in_array($fieldNameKey, self::DOCUMENT_TITLE_ALIASES, true))
			{
				return $this->addError($result, 'Document title placeholders are not supported', $fieldName);
			}

			if (
				in_array($fieldNameKey, self::DOCUMENT_NUMBER_ALIASES, true)
				|| in_array($fieldNameKey, self::DOCUMENT_CREATE_TIME_ALIASES, true)
			)
			{
				continue;
			}

			if (!isset($allowedFields[$fieldName]))
			{
				return $this->addError($result, 'Placeholder is not available in the template body: ' . $fieldName, $fieldName);
			}
		}

		return $result;
	}

	public function render(array $values, array $rawValueKinds, array $bodyFieldNames): Result
	{
		$result = new Result();
		$bodyFieldNames = array_fill_keys($bodyFieldNames, true);

		if ($this->hasRepeatedBlockPlaceholder())
		{
			return $this->addError($result, 'Repeated block placeholders are not supported');
		}

		foreach ($this->getFieldNames() as $fieldName)
		{
			$fieldNameKey = mb_strtolower($fieldName);
			if ($this->isDocumentSourceBranch($fieldNameKey))
			{
				return $this->addError($result, 'Document source placeholders are not supported', $fieldName);
			}

			if (in_array($fieldNameKey, self::DOCUMENT_TITLE_ALIASES, true))
			{
				return $this->addError($result, 'Document title placeholders are not supported', $fieldName);
			}

			if (
				!in_array($fieldNameKey, self::DOCUMENT_NUMBER_ALIASES, true)
				&& !in_array($fieldNameKey, self::DOCUMENT_CREATE_TIME_ALIASES, true)
				&& !isset($bodyFieldNames[$fieldName])
			)
			{
				return $this->addError($result, 'Placeholder is not available in the template body: ' . $fieldName, $fieldName);
			}

			if (isset($rawValueKinds[$fieldName]) && $this->isNonScalarKind($rawValueKinds[$fieldName]))
			{
				return $this->addError($result, 'Non-scalar placeholder values are not supported: ' . $fieldName, $fieldName);
			}

			if (isset($values[$fieldName]) && $this->isNonScalarValue($values[$fieldName]))
			{
				return $this->addError($result, 'Non-scalar placeholder values are not supported: ' . $fieldName, $fieldName);
			}
		}

		if ($this->pattern === '0')
		{
			$title = $this->pattern;
		}
		else
		{
			$renderValues = $this->getRenderValues($values);
			$body = (new PlainText($this->pattern))->setValues($renderValues);
			$bodyResult = $body->process();
			if (!$bodyResult->isSuccess())
			{
				return $result->addErrors($bodyResult->getErrors());
			}

			$title = $body->getContent();
		}

		if ($title === '' || trim($title) === '')
		{
			return $this->addError($result, 'Rendered title is empty');
		}

		return $result->setData([
			'title' => mb_substr($title, 0, self::MAX_LENGTH),
		]);
	}

	private function getSystemItems(): array
	{
		$items = [];
		foreach (self::SYSTEM_FIELD_TITLE_MESSAGES as $fieldName => $messageCode)
		{
			$items[] = [
				'id' => $fieldName,
				'title' => (string)Loc::getMessage($messageCode),
				'placeholder' => '{' . $fieldName . '}',
			];
		}

		return $items;
	}

	private function getAllowedFields(Template $template, array $providerClassNames): array
	{
		$cacheKey = spl_object_id($template) . ':' . implode('|', $providerClassNames);
		if (isset($this->allowedFields[$cacheKey]))
		{
			return $this->allowedFields[$cacheKey];
		}

		$body = $template->getBody();
		$bodyFieldNames = array_fill_keys($body ? $body->getFieldNames() : [], true);

		$allowedFields = null;
		$manager = DataProviderManager::getInstance();
		foreach ($providerClassNames as $providerClassName)
		{
			$providerFields = $manager->getProviderPlaceholders($providerClassName, array_keys($bodyFieldNames));
			$providerFields = array_filter(
				$providerFields,
				fn(array $field): bool => !$this->isArrayField($field),
			);
			$allowedFields = $allowedFields === null
				? $providerFields
				: array_intersect_key($allowedFields, $providerFields);
		}

		$allowedFields = array_intersect_key($allowedFields ?? [], $bodyFieldNames);
		foreach (array_keys($allowedFields) as $fieldName)
		{
			if ($this->isReservedFieldName(mb_strtolower((string)$fieldName)))
			{
				unset($allowedFields[$fieldName]);
			}
		}

		$this->allowedFields[$cacheKey] = $allowedFields;

		return $allowedFields;
	}

	private function isReservedFieldName(string $fieldNameKey): bool
	{
		return $this->isDocumentSourceBranch($fieldNameKey)
			|| in_array($fieldNameKey, self::DOCUMENT_TITLE_ALIASES, true)
			|| in_array($fieldNameKey, self::DOCUMENT_NUMBER_ALIASES, true)
			|| in_array($fieldNameKey, self::DOCUMENT_CREATE_TIME_ALIASES, true);
	}

	private function isArrayField(array $field): bool
	{
		return ($field['OPTIONS']['IS_ARRAY'] ?? false) === true;
	}

	private function getRenderValues(array $values): array
	{
		$aliasGroups = [
			self::DOCUMENT_NUMBER_ALIASES,
			self::DOCUMENT_CREATE_TIME_ALIASES,
		];
		$renderValues = $values;
		foreach ($this->getFieldNames() as $fieldName)
		{
			$fieldNameKey = mb_strtolower($fieldName);
			foreach ($aliasGroups as $aliasNames)
			{
				if (!in_array($fieldNameKey, $aliasNames, true))
				{
					continue;
				}

				foreach ($aliasNames as $aliasName)
				{
					$alias = $this->findValueByName($values, $aliasName);
					if ($alias !== null)
					{
						$renderValues[$fieldName] = $alias;
					}
				}
			}
		}

		return $renderValues;
	}

	private function findValueByName(array $values, string $name): mixed
	{
		foreach ($values as $valueName => $value)
		{
			if (mb_strtolower((string)$valueName) === mb_strtolower($name))
			{
				return $value;
			}
		}

		return null;
	}

	private function isNonScalarKind(mixed $kind): bool
	{
		if (is_array($kind))
		{
			foreach (['type', 'class', 'kind'] as $key)
			{
				if (array_key_exists($key, $kind))
				{
					return $this->isNonScalarKind($kind[$key]);
				}
			}

			return true;
		}

		if (is_object($kind))
		{
			return true;
		}

		if (!is_string($kind))
		{
			return false;
		}

		$kind = mb_strtolower($kind);
		if (in_array($kind, ['array', 'object', 'bool', 'boolean'], true))
		{
			return true;
		}

		return class_exists($kind) && is_a($kind, DataProvider::class, true);
	}

	private function isNonScalarValue(mixed $value): bool
	{
		return is_array($value) || $value instanceof DataProvider;
	}

	private function hasRepeatedBlockPlaceholder(): bool
	{
		return preg_match('/\{[^}]*\.(?:BLOCK_START|BLOCK_END)(?:~[^}]*)?\}/i', $this->pattern) === 1;
	}

	private function isDocumentSourceBranch(string $fieldName): bool
	{
		return $fieldName === 'document.source' || str_starts_with($fieldName, 'document.source.');
	}

	private function addError(Result $result, string $message, ?string $fieldName = null): Result
	{
		return $result->addError(
			new Error($message, self::ERROR_CODE, ['fieldName' => $fieldName]),
		);
	}
}
