<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * Reads the portal creator's qualification wizard answers. Not a StepChecker -
 * a data provider for the activation engine: tools drive the path, industry
 * drives push-text personalization. Answers live in portal-level options
 * (module bitrix24, key cjm-<fieldId>, JSON with an itemValue payload).
 *
 * Cloud-only; missing/broken values degrade to empty list / null (default path,
 * "no industry" bucket) instead of throwing. Reads by fieldId, not step number:
 * step numbering is configurable in the wizard and not enforced in code.
 *
 * The answers belong to the portal, not to a user: the wizard is filled once by the
 * creator, so no method here takes a user id.
 */
class QualificationChecker
{
	private const TOOLS_FIELD_ID = 'cjm-select-tools';
	private const INDUSTRY_FIELD_ID = 'cjm-business';

	/**
	 * Parsed answers by fieldId. The answers belong to the portal, so a pass over the
	 * whole selection parses each of them once instead of once per user: the option itself
	 * is cached for the hit, its JSON is not.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fieldValues = [];

	/**
	 * Tool codes selected by the creator (cjm-select-tools is multi-select).
	 * The 'other' code is filtered out. Path is chosen from these by priority
	 * (see PATH-MAP).
	 *
	 * @return string[]
	 */
	public function getToolValues(): array
	{
		$data = $this->getFieldValue(self::TOOLS_FIELD_ID);
		$items = $data['itemValue'] ?? null;
		if (!is_array($items))
		{
			return [];
		}

		$codes = [];
		foreach ($items as $item)
		{
			$value = is_array($item) ? ($item['value'] ?? '') : '';
			if (is_string($value) && $value !== '' && $value !== 'other')
			{
				$codes[] = $value;
			}
		}

		return $codes;
	}

	/**
	 * Industry code (cjm-business is a single-select; itemValue is a string).
	 * See INDUSTRY-MAP.
	 */
	public function getIndustryValue(): ?string
	{
		$data = $this->getFieldValue(self::INDUSTRY_FIELD_ID);
		$value = $data['itemValue'] ?? null;

		return (is_string($value) && $value !== '') ? $value : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getFieldValue(string $fieldId): array
	{
		return $this->fieldValues[$fieldId] ??= $this->readFieldValue($fieldId);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function readFieldValue(string $fieldId): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return [];
		}

		$raw = $this->getRawOption($fieldId);
		if ($raw === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	protected function getRawOption(string $fieldId): string
	{
		return (string)Option::get('bitrix24', $fieldId, '');
	}
}
