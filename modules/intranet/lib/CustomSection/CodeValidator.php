<?php

namespace Bitrix\Intranet\CustomSection;

use Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class CodeValidator
{
	public const ERROR_CODE_INVALID = 'INVALID_ADDRESS';
	public const ERROR_CODE_ALREADY_EXISTS = 'ADDRESS_ALREADY_EXISTS';
	public const ERROR_CODE_SECTION_NOT_FOUND = Manager::ERROR_CODE_SECTION_NOT_FOUND;

	// Stricter than Manager::VALID_CODE_REGEX: manually entered codes must be a clean URL segment
	private const MANUAL_CODE_REGEX = '/^[a-z0-9_]{1,255}$/D';

	public function validateSection(string $code, ?int $excludeId = null): Result
	{
		$result = $this->validateFormat($code);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$query = CustomSectionTable::query()
			->setSelect(['ID'])
			->where('CODE', $code)
			->setLimit(1)
		;
		if ($excludeId !== null)
		{
			$query->whereNot('ID', $excludeId);
		}

		if ($query->fetch())
		{
			$result->addError(new Error(
				Loc::getMessage('INTRANET_CUSTOM_SECTION_CODE_VALIDATOR_SECTION_ALREADY_EXISTS'),
				self::ERROR_CODE_ALREADY_EXISTS,
			));
		}

		return $result;
	}

	public function validatePage(string $code, int $sectionId, ?int $excludeId = null): Result
	{
		$result = $this->validateFormat($code);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$section = CustomSectionTable::query()
			->setSelect(['CODE'])
			->where('ID', $sectionId)
			->fetch()
		;
		if (!$section)
		{
			$result->addError(new Error(
				Loc::getMessage('INTRANET_CUSTOM_SECTION_CODE_VALIDATOR_SECTION_NOT_FOUND'),
				self::ERROR_CODE_SECTION_NOT_FOUND,
			));

			return $result;
		}

		if (
			$this->isPageCodeBusy($code, $sectionId, $excludeId)
			|| in_array($code, $this->getSystemPagesCodes((string)$section['CODE']), true)
		)
		{
			$result->addError(new Error(
				Loc::getMessage('INTRANET_CUSTOM_SECTION_CODE_VALIDATOR_PAGE_ALREADY_EXISTS'),
				self::ERROR_CODE_ALREADY_EXISTS,
			));
		}

		return $result;
	}

	private function validateFormat(string $code): Result
	{
		$result = new Result();

		if (!preg_match(self::MANUAL_CODE_REGEX, $code))
		{
			$result->addError(new Error(
				Loc::getMessage('INTRANET_CUSTOM_SECTION_CODE_VALIDATOR_INVALID'),
				self::ERROR_CODE_INVALID,
			));
		}

		return $result;
	}

	private function isPageCodeBusy(string $code, int $sectionId, ?int $excludeId): bool
	{
		$query = CustomSectionPageTable::query()
			->setSelect(['ID'])
			->where('CUSTOM_SECTION_ID', $sectionId)
			->where('CODE', $code)
			->setLimit(1)
		;
		if ($excludeId !== null)
		{
			$query->whereNot('ID', $excludeId);
		}

		return (bool)$query->fetch();
	}

	private function getSystemPagesCodes(string $sectionCode): array
	{
		/** @var Manager $manager */
		$manager = ServiceLocator::getInstance()->get('intranet.customSection.manager');

		return $manager->getSystemPagesCodes($sectionCode, true);
	}
}
