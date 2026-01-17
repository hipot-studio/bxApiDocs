<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Rest\V3\Schema\GeneratedDto;

/**
 * Class Generator
 *
 * Generates PHP classes that extend Dto and adds properties / methods to them.
 */
class Generator
{
	/**
	 * Generate the class code, evaluate it and return FQCN of generated class.
	 *
	 * @return string Fully qualified class name
	 * @throws \RuntimeException
	 */
	public static function generateByDto(GeneratedDto $generatedDto): string
	{
		$fqcn = $generatedDto->getFQCN();
		// If class already exists, return it to avoid redeclare errors.
		if (class_exists($fqcn, false))
		{
			return $fqcn;
		}

		if (empty($generatedDto->fields))
		{
			throw new \InvalidArgumentException('Fields array cannot be empty');
		}

		foreach ($generatedDto->fields as $field)
		{
			if (!$field instanceof DtoField)
			{
				throw new \InvalidArgumentException('Invalid field type');
			}
		}

		$fieldsCode = [];
		foreach ($generatedDto->fields as $field)
		{
			// serialize field to plain array using toArray()
			$arr = $field->toArray();
			$fieldsCode[] = self::exportValue($arr);
		}

		// Build namespace declaration
		$nsDecl = $generatedDto->namespace ? "namespace $generatedDto->namespace;" : '';

		$code = $nsDecl;
		$code .= " class $generatedDto->className extends \Bitrix\Rest\V3\Dto\Dto {";
		$fieldsBody = implode(",", array_map(fn($s) => "$s", $fieldsCode));
		$code .= "protected function getExtraFields(): array { return array_map(function(\$item) { return \\Bitrix\\Rest\\V3\\Dto\\DtoField::fromArray(\$item); }, [$fieldsBody]);}";
		$code .= "}";

		try
		{
			eval($code);
		}
		catch (\ParseError $e)
		{
			throw new \RuntimeException('Failed to evaluate generated class code: ' . $e->getMessage());
		}

		return $fqcn;
	}

	/**
	 * Export value to PHP code using var_export-friendly approach.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function exportValue(mixed $value): string
	{
		if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null)
		{
			return var_export($value, true);
		}

		if (is_array($value))
		{
			// Use var_export for arrays (stable for arrays of scalars/arrays)
			return var_export($value, true);
		}

		if (is_object($value))
		{
			// Avoid generating unsafe object constructions; fallback to null.
			return 'null';
		}

		// Fallback
		return 'null';
	}
}
