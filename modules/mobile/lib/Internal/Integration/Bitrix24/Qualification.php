<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Bitrix24;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * Integration layer for the qualification wizard of the bitrix24 domain. All other classes of the
 * mobile module must use this class instead of reading the answers of the wizard directly.
 *
 * The wizard is filled once, by the creator, so the answers belong to the portal and no method takes
 * a user. They are portal options of the bitrix24 module, a JSON with an itemValue payload per field
 * id; fields are read by id rather than by step number, which the wizard does not fix.
 *
 * Every answer is null when it is unknown: outside the cloud, never given, or not in the shape the
 * wizard stores. Choosing what an unknown answer means - a default path, the smaller form - is up to
 * the rule that asks. An answer that cannot be read at all throws what it met, since a failure is not
 * an unknown.
 */
class Qualification
{
	private const TOOLS_FIELD_ID = 'cjm-select-tools';
	private const INDUSTRY_FIELD_ID = 'cjm-business';
	private const COMPANY_SIZE_FIELD_ID = 'cjm-employee-count';

	/** The answer of the multi-select that names no tool. */
	private const NO_TOOL = 'other';

	/**
	 * Codes of the tools the creator selected, "other" left out.
	 *
	 * @return string[]|null
	 */
	public function getTools(): ?array
	{
		$items = $this->readAnswer(self::TOOLS_FIELD_ID);
		if (!is_array($items))
		{
			return null;
		}

		$codes = [];
		foreach ($items as $item)
		{
			$value = is_array($item) ? ($item['value'] ?? null) : null;
			if (is_string($value) && $value !== '' && $value !== self::NO_TOOL)
			{
				$codes[] = $value;
			}
		}

		return $codes;
	}

	public function getIndustry(): ?string
	{
		$value = $this->readAnswer(self::INDUSTRY_FIELD_ID);

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * The upper bound of the size range the creator picked: "51-100" is 100, "500+" is 500.
	 */
	public function getCompanySizeUpperBound(): ?int
	{
		$value = $this->readAnswer(self::COMPANY_SIZE_FIELD_ID);
		if (!is_string($value))
		{
			return null;
		}

		$isRange = preg_match('/^\d+-(\d+)$/D', $value, $matches) === 1;
		if ($isRange || preg_match('/^(\d+)\+$/D', $value, $matches) === 1)
		{
			return (int)$matches[1];
		}

		return null;
	}

	protected function isCloud(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function readRawAnswer(string $fieldId): string
	{
		return (string)Option::get('bitrix24', $fieldId, '');
	}

	/**
	 * The itemValue payload of a field, null when there is none to read.
	 */
	private function readAnswer(string $fieldId): mixed
	{
		if (!$this->isCloud())
		{
			return null;
		}

		$raw = $this->readRawAnswer($fieldId);
		try
		{
			$decoded = $raw === '' ? null : Json::decode($raw);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($decoded) ? ($decoded['itemValue'] ?? null) : null;
	}
}
