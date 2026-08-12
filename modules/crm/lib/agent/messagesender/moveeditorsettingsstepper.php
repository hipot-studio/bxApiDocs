<?php

declare(strict_types=1);

namespace Bitrix\Crm\Agent\MessageSender;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Update\Stepper;
use Bitrix\Main\Web\Json;

final class MoveEditorSettingsStepper extends Stepper
{
	protected static $moduleId = 'crm';

	public function execute(array &$option)
	{
		$lastId = (int)($option['lastId'] ?? 0);

		$sqlString = $this->buildSql($lastId);

		$result = \Bitrix\Main\Application::getConnection()->query($sqlString);

		$processedCount = 0;
		while ($row = $result->fetch())
		{
			$lastId = (int)$row['ID'];
			$processedCount++;

			$this->processRow($row);
		}

		if ($processedCount < $this->getStepLimit())
		{
			return self::FINISH_EXECUTION;
		}

		$option['lastId'] = $lastId;

		return self::CONTINUE_EXECUTION;
	}

	private function buildSql(int $lastId): string
	{
		$sqlString = 'SELECT ID, USER_ID, CATEGORY, NAME, VALUE, COMMON FROM b_user_option';

		$whereParts = [];

		if ($lastId > 0)
		{
			$whereParts = ['ID > ' . $lastId];
		}

		$whereParts[] = "CATEGORY = 'crm'";
		$whereParts[] = "NAME = 'crm.messagesender.editor'";

		$sqlString .= ' WHERE ' . implode(' AND ', $whereParts);
		$sqlString .= ' ORDER BY ID ASC LIMIT ' . $this->getStepLimit();

		return $sqlString;
	}

	protected function getStepLimit(): int
	{
		return (int)Option::get('crm', 'user_options_stepper_limit', 50);
	}

	protected function processRow(array $row): void
	{
		$value = unserialize($row['VALUE'], ['allowed_classes' => false]);
		if (!is_array($value))
		{
			$this->deleteOption($row);

			return;
		}

		foreach ($value as $sceneId => $preferencesJson)
		{
			if (!is_string($sceneId) || empty($sceneId))
			{
				continue;
			}

			if (!is_string($preferencesJson) || empty($preferencesJson))
			{
				continue;
			}

			try
			{
				$preferences = Json::decode($preferencesJson);
			}
			catch (ArgumentException)
			{
				continue;
			}

			// User may have already changed settings before the stepper processed their data (on large portals)
			if (!$this->isOptionSet($sceneId, $row))
			{
				$this->saveOption($sceneId, $preferences, $row);
			}
		}

		$this->deleteOption($row);
	}

	private function isOptionSet(string $sceneId, array $originalRow): bool
	{
		if (!isset($originalRow['USER_ID']))
		{
			return false;
		}

		$option = \CUserOptions::GetOption(
			'messageservice.message.editor',
			$sceneId,
			false,
			(int)$originalRow['USER_ID'],
		);

		return $option !== false;
	}

	private function saveOption(string $sceneId, array $preferences, array $originalRow): void
	{
		if (!isset($originalRow['COMMON'], $originalRow['USER_ID']))
		{
			return;
		}

		\CUserOptions::SetOption(
			'messageservice.message.editor',
			$sceneId,
			$preferences,
			$originalRow['COMMON'] === 'Y',
			(int)$originalRow['USER_ID'],
		);
	}

	private function deleteOption(array $row): void
	{
		if (!isset($row['CATEGORY'], $row['NAME'], $row['COMMON'], $row['USER_ID']))
		{
			return;
		}

		\CUserOptions::DeleteOption(
			$row['CATEGORY'],
			$row['NAME'],
			$row['COMMON'] === 'Y',
			(int)$row['USER_ID']
		);
	}
}
