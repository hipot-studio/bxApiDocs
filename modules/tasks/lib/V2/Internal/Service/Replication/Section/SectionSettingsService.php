<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

class SectionSettingsService
{
	public const ERROR_SAVE_FAILED = 'SETTINGS_SAVE_FAILED';

	private const OPTION_CATEGORY = 'tasks.replication';
	private const OPTION_NAME = 'section';

	public function get(int $userId): SectionSettings
	{
		if ($userId <= 0)
		{
			return SectionSettings::createDefault();
		}

		return SectionSettings::createFromStored($this->readOptions($userId));
	}

	public function getDto(
		int $userId,
		bool $hasImportAccess = false,
		bool $hasExportAccess = false,
		?string $importUrl = null,
	): array
	{
		return $this->get($userId)->toDto(
			hasImportAccess: $hasImportAccess,
			hasExportAccess: $hasExportAccess,
			importUrl: $importUrl,
		);
	}

	public function save(int $userId, array $input): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError(
				new Error(
					'Invalid user.',
					SectionSettings::ERROR_VALUE_INVALID,
					['field' => 'userId'],
				),
			);
		}

		$settingsResult = SectionSettings::createFromInput($input);
		if (!$settingsResult->isSuccess())
		{
			return $result->addErrors($settingsResult->getErrors());
		}

		/** @var SectionSettings $settings */
		$settings = $settingsResult->getData()['settings'];
		$stored = $this->readOptions($userId);
		$stored = is_array($stored) ? $stored : [];
		$stored['groupBy'] = $settings->groupBy;
		$stored['sortBy'] = $settings->sortBy;
		$stored['sortDirection'] = $settings->sortDirection;

		try
		{
			$isSaved = $this->writeOptions($userId, $stored);
		}
		catch (Throwable)
		{
			$isSaved = false;
		}

		if (!$isSaved)
		{
			return $result->addError(
				new Error(
					'Failed to save section settings.',
					self::ERROR_SAVE_FAILED,
				),
			);
		}

		$result->setData(['settings' => $settings]);

		return $result;
	}

	protected function readOptions(int $userId): mixed
	{
		return \CUserOptions::GetOption(
			category: self::OPTION_CATEGORY,
			name: self::OPTION_NAME,
			default_value: [],
			user_id: $userId,
		);
	}

	protected function writeOptions(int $userId, array $options): bool
	{
		return (bool)\CUserOptions::SetOption(
			category: self::OPTION_CATEGORY,
			name: self::OPTION_NAME,
			value: $options,
			user_id: $userId,
		);
	}
}
