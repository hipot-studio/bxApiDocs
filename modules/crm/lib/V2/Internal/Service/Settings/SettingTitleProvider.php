<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

Loc::loadMessages(__FILE__);

/** @internal */
final class SettingTitleProvider
{
	private const MESSAGE_PREFIX = 'CRM_V2_SETTING_';
	private const DESCRIPTION_SUFFIX = '_DESCRIPTION';
	private const NOTE_SUFFIX = '_NOTE';
	private const COPILOT_FILL_FIELDS_SETTING_IDS = [
		'crm.copilot.fill_fields.lead.enabled',
		'crm.copilot.fill_fields.contact.enabled',
		'crm.copilot.fill_fields.company.enabled',
		'crm.copilot.fill_fields.deal.enabled',
	];

	/** @throws SystemException */
	public static function getTitle(string $id): string
	{
		$message = Loc::getMessage(self::getMessageCode($id), self::getReplacements($id));
		if (!is_string($message) || $message === '')
		{
			throw new SystemException("Localization for setting '{$id}' is missing");
		}

		return $message;
	}

	/** @throws SystemException */
	public static function getOptionTitle(string $id, string $optionCode): string
	{
		$message = Loc::getMessage(self::getMessageCode($id . '.option.' . $optionCode));
		if (!is_string($message) || $message === '')
		{
			throw new SystemException(
				"Localization for setting option '{$id}.{$optionCode}' is missing",
			);
		}

		return $message;
	}

	public static function getDescription(string $id): ?string
	{
		$message = Loc::getMessage(
			self::getMessageCode($id) . self::DESCRIPTION_SUFFIX,
			self::getReplacements($id),
		);

		return is_string($message) && $message !== '' ? $message : null;
	}

	public static function getNote(string $id): ?string
	{
		$message = Loc::getMessage(self::getMessageCode($id) . self::NOTE_SUFFIX);

		return is_string($message) && $message !== '' ? $message : null;
	}

	private static function getMessageCode(string $id): string
	{
		return self::MESSAGE_PREFIX . mb_strtoupper(str_replace('.', '_', $id));
	}

	private static function getReplacements(string $id): array
	{
		if (!in_array($id, self::COPILOT_FILL_FIELDS_SETTING_IDS, true))
		{
			return [];
		}

		return ['#COPILOT_NAME#' => AIManager::getCopilotName()];
	}
}
