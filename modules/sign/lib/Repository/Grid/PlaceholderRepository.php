<?php

namespace Bitrix\Sign\Repository\Grid;

use Bitrix\Main;

class PlaceholderRepository
{
	private const CATEGORY = 'sign.b2e.placeholders';

	public const SELECTION_TYPE_MY_COMPANY = 'myCompany';
	public const SELECTION_TYPE_HCM_LINK_COMPANY = 'hcmLinkCompany';

	private const ALLOWED_SELECTION_TYPES = [
		self::SELECTION_TYPE_MY_COMPANY,
		self::SELECTION_TYPE_HCM_LINK_COMPANY,
	];

	public function saveLastSelectionBySelectorTypeAction(string $selectorType, int $value, int $userId): Main\Result
	{
		$result = new Main\Result();
		if (!$this->isAllowedSelectorType($selectorType))
		{
			return $result->addError(new Main\Error('Not allowed selector type'));
		}

		\CUserOptions::SetOption(
			self::CATEGORY,
			$selectorType,
			$value,
			false,
			$userId,
		);

		return $result;
	}
	public function getLastSelectionBySelectorTypeAction(string $selectorType, int $userId): Main\Result
	{
		$result = new Main\Result();
		if (!$this->isAllowedSelectorType($selectorType))
		{
			return $result->addError(new Main\Error('Not allowed selector type'));
		}

		$value = \CUserOptions::GetOption(
			self::CATEGORY,
			$selectorType,
			null,
			$userId,
		);

		return $result->setData(['value' => (int)$value]);
	}

	private function isAllowedSelectorType(string $selectorType): bool
	{
		return in_array($selectorType, self::ALLOWED_SELECTION_TYPES, true);
	}
}