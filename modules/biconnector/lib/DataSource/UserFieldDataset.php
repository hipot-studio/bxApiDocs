<?php

namespace Bitrix\BIConnector\DataSource;

use Bitrix\BIConnector\DataSource\Field\DateField;
use Bitrix\BIConnector\DataSource\Field\DateTimeField;
use Bitrix\BIConnector\DataSource\Field\DoubleField;
use Bitrix\BIConnector\DataSource\Field\BoolField;
use Bitrix\BIConnector\DataSource\Field\IntegerField;
use Bitrix\BIConnector\DataSource\Field\StringField;
use Bitrix\BIConnector\MemoryCache;
use Bitrix\BIConnector\PrettyPrinter;
use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\BIConnector\UserField\ProxyUserFieldManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UserField\Types;

abstract class UserFieldDataset extends Dataset
{
	protected array $userFields = [];

	abstract protected function getRawUserFields(): array;

	/**
	 * @return Result
	 */
	protected function onBeforeEvent(): Result
	{
		$result = parent::onBeforeEvent();

		$this->userFields = $this->getRawUserFields();

		if (empty($this->userFields))
		{
			$result->addError(new Error('`User fields` not found'));
		}

		return $result;
	}

	protected function fieldTypeFabric(string $type, bool $isMultiple, string $fieldName): DatasetField
	{
		if ($isMultiple || !DatasetSettings::isTypingEnabled())
		{
			return
				(new StringField($fieldName))
					->setSystem(false)
			;
		}

		$field = match ($type) {
			Types\IntegerType::USER_TYPE_ID => new IntegerField($fieldName),
			Types\DoubleType::USER_TYPE_ID => new DoubleField($fieldName),
			Types\DateType::USER_TYPE_ID => new DateField($fieldName),
			Types\DateTimeType::USER_TYPE_ID => new DateTimeField($fieldName),
			Types\BooleanType::USER_TYPE_ID => new BoolField($fieldName),
			default => new StringField($fieldName),
		};

		$field->setSystem(false);

		return $field;
	}

	protected function getFields(): array
	{
		$fields = [];

		foreach ($this->userFields as $userField)
		{
			$dbType = '';
			if ($userField['USER_TYPE'] && is_callable([$userField['USER_TYPE']['CLASS_NAME'], 'getdbcolumntype']))
			{
				$dbType = call_user_func_array([$userField['USER_TYPE']['CLASS_NAME'], 'getdbcolumntype'], [$userField]);
			}

			$field =
				($this->fieldTypeFabric($userField['USER_TYPE_ID'], $userField['MULTIPLE'] === 'Y', $userField['FIELD_NAME']))
					->setCallback(
						function($value, $dateFormats) use($userField, $dbType)
						{
							global $USER_FIELD_MANAGER;

							if ($dbType === 'date' || $dbType === 'datetime')
							{
								if ($value === null || $value === '')
								{
									return null;
								}

								$format =
									$dbType === 'date'
										? $dateFormats['date_format_php']
										: $dateFormats['datetime_format_php']
								;

								return PrettyPrinter::formatUserFieldAsDate($userField, $value, $format);
							}

							if (
								$userField['USER_TYPE_ID'] === Types\BooleanType::USER_TYPE_ID
								&& DatasetSettings::isTypingEnabled()
							)
							{
								return (bool)$value;
							}

							$cacheKey = serialize($value);
							$cachedResult = MemoryCache::get($userField['ID'], $cacheKey);
							if (isset($cachedResult))
							{
								return $cachedResult;
							}

							if ($userField['MULTIPLE'] == 'Y')
							{
								$result = $USER_FIELD_MANAGER->onAfterFetch(
									$userField,
									unserialize($value, ['allowed_classes' => PrettyPrinter::$allowedUnserializeClassesList])
								);
							}
							else
							{
								$result = [$USER_FIELD_MANAGER->onAfterFetch($userField, $value)];
							}

							$localUF = $userField;
							$localUF['VALUE'] = $result;

							$returnResult = ProxyUserFieldManager::getText($localUF);
							MemoryCache::set($userField['ID'], $cacheKey, $returnResult);

							return $returnResult;
						}
					)
			;

			if (!empty($userField['EDIT_FORM_LABEL']))
			{
				$field->setDescription($userField['EDIT_FORM_LABEL']);
			}
			elseif (!empty($userField['USER_TYPE']['DESCRIPTION']))
			{
				$field->setDescription($userField['USER_TYPE']['DESCRIPTION']);
			}

			$fields[] = $field;
		}

		return $fields;
	}
}
