<?php

namespace Bitrix\Crm\Service\EditorAdapter\Normalizer;

use Bitrix\Main\ObjectException;
use Bitrix\Main\UserField\Dispatcher;
use Bitrix\Main\UserField\Types\BooleanType;
use Bitrix\Main\UserField\Types\DateType;

final class UserFieldValueNormalizer extends Base
{
	public function __construct(
		private readonly bool $isValueEmpty,
		private array $fieldParams,
	)
	{
	}

	public function normalize(mixed $value): array
	{
		if (!$this->isValueEmpty)
		{
			if (is_array($value))
			{
				$value = array_values($value);
			}

			if ($this->fieldParams['USER_TYPE_ID'] === DateType::USER_TYPE_ID)
			{
				$value = $this->normalizeDateValue($value);
			}
			elseif ($this->fieldParams['USER_TYPE_ID'] === BooleanType::USER_TYPE_ID)
			{
				$value = $value ? '1' : '0';
			}

			$this->fieldParams['VALUE'] = $value;
		}

		$fieldSignature = Dispatcher::instance()->getSignature($this->fieldParams);
		if ($this->isValueEmpty)
		{
			return [
				'SIGNATURE' => $fieldSignature,
				'IS_EMPTY' => true,
			];
		}

		return [
			'VALUE' => $value,
			'SIGNATURE' => $fieldSignature,
			'IS_EMPTY' => false,
		];
	}

	private function normalizeDateValue(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(fn($singleValue) => $this->normalizeDateValue($singleValue), $value);
		}

		if ($value === null || $value === '')
		{
			return $value;
		}

		try
		{
			return DateType::onAfterFetch($this->fieldParams, ['VALUE' => $value]);
		}
		catch (ObjectException)
		{
			return $value;
		}
	}
}
