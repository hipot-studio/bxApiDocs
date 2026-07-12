<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\Collab\Control\Option\OptionFactory;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\AllowGuestsInvitationField;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\CanGuestCopyTextOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\CanGuestScreenshotOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ManageMessagesAutoDelete;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ManageMessagesOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\MessagesAutoDeleteDelay;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\ShowHistoryOption;
use Bitrix\Socialnetwork\Collab\Control\Option\Type\WhoCanInviteOption;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\V2\Internal\Repository\Option\ProjectSummaryAgentOption;

class CollabOptionRepository
{
	private const SUPPORTED_OPTIONS_MAP = [
		ManageMessagesOption::DB_NAME => ManageMessagesOption::NAME,
		WhoCanInviteOption::DB_NAME => WhoCanInviteOption::NAME,
		ShowHistoryOption::DB_NAME => ShowHistoryOption::NAME,
		CanGuestCopyTextOption::DB_NAME => CanGuestCopyTextOption::NAME,
		CanGuestScreenshotOption::DB_NAME => CanGuestScreenshotOption::NAME,
		MessagesAutoDeleteDelay::DB_NAME => MessagesAutoDeleteDelay::NAME,
		ManageMessagesAutoDelete::DB_NAME => ManageMessagesAutoDelete::NAME,
		AllowGuestsInvitationField::DB_NAME => AllowGuestsInvitationField::NAME,
	];

	public function shouldShowHistory(int $collabId): bool
	{
		$options = $this->getRawOptions($collabId, [ShowHistoryOption::DB_NAME]);

		$value = $options[ShowHistoryOption::DB_NAME] ?? ShowHistoryOption::DEFAULT_VALUE;

		return $value === 'Y';
	}

	/**
	 * @param string[] $optionNames
	 * @return array<string, string>
	 */
	public function getRawOptions(int $collabId, array $optionNames = []): array
	{
		if ($collabId <= 0)
		{
			return [];
		}

		$query = CollabOptionTable::query()
			->setSelect(['NAME', 'VALUE'])
			->where('COLLAB_ID', $collabId)
		;

		if ($optionNames !== [])
		{
			$query->whereIn('NAME', $optionNames);
		}

		$rows = $query->exec()->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$name = (string)($row['NAME'] ?? '');
			if ($name === '')
			{
				continue;
			}

			$result[$name] = (string)($row['VALUE'] ?? '');
		}

		return $result;
	}

	/**
	 * @param int[] $collabIds
	 * @return array<int, bool>
	 */
	public function getOptionBatch(array $collabIds, string $optionName): array
	{
		if (empty($collabIds))
		{
			return [];
		}

		$rows = CollabOptionTable::query()
			->setSelect(['COLLAB_ID', 'VALUE'])
			->whereIn('COLLAB_ID', $collabIds)
			->where('NAME', $optionName)
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['COLLAB_ID']] = ($row['VALUE'] ?? '') === 'Y';
		}

		return $result;
	}

	/**
	 * @param int[] $collabIds
	 * @return array<int, string>
	 */
	public function getOptionValueBatch(array $collabIds, string $optionName): array
	{
		if ($collabIds === [])
		{
			return [];
		}

		Collection::normalizeArrayValuesByInt($collabIds, false);

		if ($collabIds === [])
		{
			return [];
		}

		$queryResult = CollabOptionTable::query()
			->setSelect(['COLLAB_ID', 'VALUE'])
			->whereIn('COLLAB_ID', $collabIds)
			->where('NAME', $optionName)
			->exec()
		;

		$result = [];
		while ($row = $queryResult->fetch())
		{
			$result[(int)$row['COLLAB_ID']] = (string)($row['VALUE'] ?? '');
		}

		return $result;
	}

	/**
	 * @return array<string, string>|null
	 */
	public function getOptions(int $collabId): ?array
	{
		if ($collabId <= 0)
		{
			return null;
		}

		$rows = CollabOptionTable::query()
			->setSelect(['NAME', 'VALUE'])
			->where('COLLAB_ID', $collabId)
			->whereIn('NAME', array_keys(self::SUPPORTED_OPTIONS_MAP))
			->exec()
			->fetchAll()
		;

		if ($rows === [])
		{
			return null;
		}

		$options = OptionFactory::DEFAULT_OPTIONS;
		foreach ($rows as $row)
		{
			$optionName = self::SUPPORTED_OPTIONS_MAP[$row['NAME'] ?? ''] ?? null;
			if ($optionName === null || !is_scalar($row['VALUE'] ?? null))
			{
				continue;
			}

			$options[$optionName] = (string)$row['VALUE'];
		}

		return $options;
	}

	public function setOption(int $collabId, string $optionName, string $value): void
	{
		CollabOptionTable::merge(
			[
				'COLLAB_ID' => $collabId,
				'NAME' => $optionName,
				'VALUE' => $value,
			],
			[
				'VALUE' => $value,
			],
			['COLLAB_ID', 'NAME'],
		);
	}

	public function setProjectSummaryAgentOption(int $collabId, bool $value): void
	{
		if ($collabId <= 0)
		{
			throw new \InvalidArgumentException('Collab ID must be positive integer');
		}

		$this->setOption(
			collabId: $collabId,
			optionName: ProjectSummaryAgentOption::DB_NAME,
			value: $value ? 'Y' : 'N',
		);
	}

	public function getProjectSummaryAgentOptionFilter(): Query
	{
		return
			CollabOptionTable::query()
				->setSelect(['COLLAB_ID'])
				->where('NAME', ProjectSummaryAgentOption::DB_NAME)
				->where('VALUE', ProjectSummaryAgentOption::HAS_AGENT)
		;
	}
}
