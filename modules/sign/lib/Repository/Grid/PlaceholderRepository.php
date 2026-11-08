<?php

namespace Bitrix\Sign\Repository\Grid;

use Bitrix\Main;
use Bitrix\Sign\Service\Container;

class PlaceholderRepository
{
	private const CATEGORY = 'sign.b2e.placeholders';

	public const SELECTION_TYPE_MY_COMPANY = 'myCompany';
	public const SELECTION_TYPE_HCM_LINK_COMPANY = 'hcmLinkCompany';

	private const ALLOWED_SELECTION_TYPES = [
		self::SELECTION_TYPE_MY_COMPANY,
		self::SELECTION_TYPE_HCM_LINK_COMPANY,
	];

	public const PERSONAL_SETTING_RECENT_FIELD_CODES = 'recentFieldCodes';

	private const ALLOWED_PERSONAL_SETTINGS = [
		self::PERSONAL_SETTING_RECENT_FIELD_CODES,
	];

	// Refusals of a write: the phrase of the code belongs to the action that answers the panel
	public const ERROR_EMPTY_FIELD_CODE = 'emptyFieldCode';
	public const ERROR_NOT_ALLOWED_FIELD_CODE = 'notAllowedFieldCode';

	private const MAX_RECENT_FIELD_CODES = 20;

	// Modifier tail after a tilde, in characters
	private const MAX_MODIFIER_TAIL_LENGTH = 128;

	/**
	 * Keeps the size of the option proportional; counted in characters, the unit
	 * FieldAliasService::MAX_ALIAS_LENGTH uses. Well above that owner on purpose: a refused legal code
	 * would silently drop a field out of the history of the user.
	 */
	private const MAX_FIELD_CODE_BASE_LENGTH = 512;

	// Characters the parameters of the catalog are spelled with; a new one has to be allowed here
	private const MODIFIER_TAIL_PATTERN = '/^[A-Za-z0-9#=,.:_ ]+$/D';

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

	/**
	 * Recently copied field codes, the most recent first; a code carries the modifiers of that copy,
	 * so one field copied with two different sets keeps two entries of its own.
	 *
	 * @return array{recentFieldCodes: string[]}
	 */
	public function getPersonalSettings(int $userId): array
	{
		return [
			self::PERSONAL_SETTING_RECENT_FIELD_CODES => $this->getRecentFieldCodes($userId),
		];
	}

	/**
	 * @return string[]
	 */
	public function getRecentFieldCodes(int $userId): array
	{
		return $this->sanitizeRecentFieldCodes(
			$this->readPersonalSetting(self::PERSONAL_SETTING_RECENT_FIELD_CODES, $userId),
		);
	}

	public function pushRecentFieldCode(int $userId, string $fieldCode): Main\Result
	{
		$result = new Main\Result();
		if ($fieldCode === '')
		{
			return $result->addError(new Main\Error('Empty field code', self::ERROR_EMPTY_FIELD_CODE));
		}

		if (!$this->isAllowedFieldCode($fieldCode))
		{
			return $result->addError(new Main\Error('Not allowed field code', self::ERROR_NOT_ALLOWED_FIELD_CODE));
		}

		return $this->savePersonalSettings($userId, [
			self::PERSONAL_SETTING_RECENT_FIELD_CODES => $this->pushFieldCodeToRecent(
				$this->getRecentFieldCodes($userId),
				$fieldCode,
			),
		]);
	}

	/**
	 * Volume limits are applied here, so they hold whichever method wrote the value.
	 *
	 * @param array<string, array> $settings values by setting name
	 */
	public function savePersonalSettings(int $userId, array $settings): Main\Result
	{
		$result = new Main\Result();
		if ($userId < 1)
		{
			return $result->addError(new Main\Error('Not allowed user'));
		}

		foreach (array_keys($settings) as $settingName)
		{
			if (!$this->isAllowedPersonalSetting((string)$settingName))
			{
				return $result->addError(new Main\Error('Not allowed personal setting'));
			}
		}

		foreach ($settings as $settingName => $value)
		{
			$isSaved = \CUserOptions::SetOption(
				self::CATEGORY,
				$settingName,
				$this->sanitizePersonalSetting((string)$settingName, $value),
				false,
				$userId,
			);

			if (!$isSaved)
			{
				$result->addError(new Main\Error('Could not save personal setting'));
			}
		}

		return $result;
	}

	private function isAllowedPersonalSetting(string $settingName): bool
	{
		return in_array($settingName, self::ALLOWED_PERSONAL_SETTINGS, true);
	}

	/**
	 * An unreadable option must not fail the scenario: the panel works with an empty history.
	 */
	private function readPersonalSetting(string $settingName, int $userId): array
	{
		if ($userId < 1 || !$this->isAllowedPersonalSetting($settingName))
		{
			return [];
		}

		try
		{
			$value = \CUserOptions::GetOption(self::CATEGORY, $settingName, [], $userId);
		}
		catch (\Throwable)
		{
			$this->logUnreadablePersonalSetting($settingName);

			return [];
		}

		if (!is_array($value))
		{
			$this->logUnreadablePersonalSetting($settingName);

			return [];
		}

		return $value;
	}

	private function logUnreadablePersonalSetting(string $settingName): void
	{
		Container::instance()->getLogger('Repository')->debug(
			'placeholder panel: personal setting {setting} is unreadable',
			['setting' => $settingName],
		);
	}

	private function sanitizePersonalSetting(string $settingName, mixed $value): array
	{
		return match ($settingName)
		{
			self::PERSONAL_SETTING_RECENT_FIELD_CODES => $this->sanitizeRecentFieldCodes($value),
		};
	}

	/**
	 * @return string[]
	 */
	private function sanitizeRecentFieldCodes(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$fieldCodes = [];
		foreach ($value as $fieldCode)
		{
			if (
				is_string($fieldCode)
				&& $this->isAllowedFieldCode($fieldCode)
				&& !in_array($fieldCode, $fieldCodes, true)
			)
			{
				$fieldCodes[] = $fieldCode;
			}
		}

		return array_slice($fieldCodes, 0, self::MAX_RECENT_FIELD_CODES);
	}

	/**
	 * Bounds both parts of a stored code, so the size of the option holds whichever path writes the
	 * history. Which field the code names is not checked here: the action verifies it against the
	 * codes visible to the user.
	 */
	private function isAllowedFieldCode(string $fieldCode): bool
	{
		if ($fieldCode === '' || str_contains($fieldCode, '{') || str_contains($fieldCode, '}'))
		{
			return false;
		}

		$tildePosition = mb_strpos($fieldCode, '~');
		if ($tildePosition === false)
		{
			return mb_strlen($fieldCode) <= self::MAX_FIELD_CODE_BASE_LENGTH;
		}

		if ($tildePosition === 0)
		{
			return false;
		}

		$tail = mb_substr($fieldCode, $tildePosition + 1);

		return $tildePosition <= self::MAX_FIELD_CODE_BASE_LENGTH
			&& mb_strlen($tail) <= self::MAX_MODIFIER_TAIL_LENGTH
			&& preg_match(self::MODIFIER_TAIL_PATTERN, $tail) === 1
		;
	}

	/**
	 * @param string[] $fieldCodes
	 *
	 * @return string[]
	 */
	private function pushFieldCodeToRecent(array $fieldCodes, string $fieldCode): array
	{
		$fieldCodes = array_values(
			array_filter($fieldCodes, static fn(string $code): bool => $code !== $fieldCode),
		);
		array_unshift($fieldCodes, $fieldCode);

		return array_slice($fieldCodes, 0, self::MAX_RECENT_FIELD_CODES);
	}
}
