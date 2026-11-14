<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

final class CatalogFields
{
	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function getDefinitions(): array
	{
		return [
			'name' => self::field('string', true, ''),
			'type' => self::field('enum', true, 'C', ['C', 'S']),
			'provider' => self::field('string'),
			'text' => self::field('string', false, ''),
			'html' => self::field('boolean', false, false),
			'label' => self::field('string', false, ''),
			'url' => self::field('string'),
			'templateFields' => self::field('map', false, []),
			'legalEntityId' => self::field('integer'),
			'languageId' => self::field('string', true),
			'purpose' => self::field('string'),
			'expiryRule' => self::field('enum', false, 'none', ['none', 'period', 'date']),
			'validFrom' => self::field('date'),
			'validTo' => self::field('date'),
			'expiryPeriodUnit' => self::field('enum', false, 'days', ['days', 'months', 'years'],
				['field' => 'expiryRule', 'value' => 'period']),
			'expiryPeriodAmount' => self::field('integer', true, null, [],
				['field' => 'expiryRule', 'value' => 'period']),
			'expiryDate' => self::field('date', true, null, [],
				['field' => 'expiryRule', 'value' => 'date']),
			'tags' => self::field('list', false, []),
			'responsibleId' => self::field('integer'),
			'documentId' => self::field('integer', false, null, [], null, true),
			'versionId' => self::field('integer', false, null, [], null, true),
			'versionNumber' => self::field('integer', false, null, [], null, true),
			'currentVersionId' => self::field('integer', false, null, [], null, true),
		];
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	public static function validateInput(array $values): array
	{
		$definitions = self::getDefinitions();
		$errors = [];
		$expiryRule = $values['expiryRule'] ?? 'none';

		foreach ($values as $key => $value)
		{
			if (!isset($definitions[$key]))
			{
				$errors[$key] = 'UNKNOWN_FIELD';
				continue;
			}

			$definition = $definitions[$key];
			if ($definition['readonly'])
			{
				$errors[$key] = 'READ_ONLY';
				continue;
			}

			$dependency = $definition['dependency'] ?? null;
			if ($dependency !== null && $expiryRule !== $dependency['value'])
			{
				continue;
			}

			if ($value === null || $value === '')
			{
				if ($definition['required'])
				{
					$errors[$key] = 'REQUIRED';
				}
				continue;
			}
			if (in_array($key, ['name', 'languageId'], true) && is_string($value) && trim($value) === '')
			{
				$errors[$key] = 'REQUIRED';
				continue;
			}

			if (!self::matchesType($value, $definition))
			{
				$errors[$key] = 'INVALID_VALUE';
			}
		}

		foreach ($definitions as $key => $definition)
		{
			if (!$definition['required'] || array_key_exists($key, $values))
			{
				continue;
			}
			if (isset($definition['dependency']) && $expiryRule !== $definition['dependency']['value'])
			{
				continue;
			}
			if ($definition['default'] === null || $definition['default'] === '')
			{
				$errors[$key] = 'REQUIRED';
			}
		}
		if (
			!isset($errors['validFrom'])
			&& !isset($errors['validTo'])
			&& isset($values['validFrom'], $values['validTo'])
			&& is_string($values['validFrom'])
			&& is_string($values['validTo'])
			&& self::isDate($values['validFrom'])
			&& self::isDate($values['validTo'])
			&& $values['validFrom'] > $values['validTo']
		)
		{
			$errors['validTo'] = 'INVALID_VALUE';
		}

		return $errors;
	}

	/**
	 * @param list<string> $options
	 * @param ?array{field: string, value: string} $dependency
	 * @return array<string, mixed>
	 */
	private static function field(
		string $type,
		bool $required = false,
		mixed $default = null,
		array $options = [],
		?array $dependency = null,
		bool $readonly = false,
	): array
	{
		$definition = [
			'type' => $type,
			'required' => $required,
			'default' => $default,
			'readonly' => $readonly,
		];
		if ($options !== [])
		{
			$definition['options'] = $options;
		}
		if ($dependency !== null)
		{
			$definition['dependency'] = $dependency;
		}

		return $definition;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private static function matchesType(mixed $value, array $definition): bool
	{
		return match ($definition['type'])
		{
			'string' => is_string($value),
			'boolean' => is_bool($value),
			'integer' => is_int($value) && $value > 0 && $value <= 2147483647,
			'enum' => is_string($value) && in_array($value, $definition['options'], true),
			'date' => is_string($value) && self::isDate($value),
			'list' => is_array($value) && array_is_list($value) && self::validTags($value),
			'map' => is_array($value) && self::validTemplateFields($value),
			default => false,
		};
	}

	private static function isDate(string $date): bool
	{
		if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $matches) !== 1)
		{
			return false;
		}

		return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
	}

	/** @param list<mixed> $tags */
	private static function validTags(array $tags): bool
	{
		foreach ($tags as $tag)
		{
			if (!is_string($tag) || trim($tag) === '')
			{
				return false;
			}
		}

		return true;
	}

	/** @param array<mixed> $fields */
	private static function validTemplateFields(array $fields): bool
	{
		foreach ($fields as $key => $value)
		{
			if (!is_string($key) || $key === '' || !is_string($value))
			{
				return false;
			}
		}

		return true;
	}
}
