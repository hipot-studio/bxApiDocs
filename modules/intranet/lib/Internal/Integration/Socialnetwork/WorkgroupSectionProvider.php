<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Intranet\Internal\Integration\Socialnetwork\Dto\WorkgroupSectionState;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Psr\Log\LoggerInterface;

class WorkgroupSectionProvider
{
	private const ACCESS_CHECK_BATCH_SIZE = 100;
	public const MAX_LINKS_TO_INSPECT = 1000;
	private const LOGGER_ID = 'intranet.LeftMenuProjectSections';

	public function getStatesByLinks(int $userId, array $links): array
	{
		if ($links === [] || $userId <= 0)
		{
			return [];
		}

		$parsedLinks = [];
		$groupIds = [];
		$seenLinks = [];
		$inspectedLinkCount = 0;
		foreach ($links as $link)
		{
			if ($inspectedLinkCount >= self::MAX_LINKS_TO_INSPECT)
			{
				break;
			}
			$inspectedLinkCount++;

			if (!is_string($link) || isset($seenLinks[$link]))
			{
				continue;
			}
			$seenLinks[$link] = true;

			$parsedLink = $this->parseLink($link);
			if ($parsedLink !== null)
			{
				$parsedLinks[$link] = $parsedLink;
				$groupIds[$parsedLink['groupId']] = true;
			}
		}
		if ($parsedLinks === [])
		{
			return [];
		}

		$groupIds = array_keys($groupIds);
		sort($groupIds, SORT_NUMERIC);

		try
		{
			$states = [];
			foreach (array_chunk($groupIds, self::ACCESS_CHECK_BATCH_SIZE) as $groupIdsChunk)
			{
				$states += $this->loadStates($userId, $groupIdsChunk);
			}
		}
		catch (\Throwable $exception)
		{
			// Fail open because the section page enforces access independently.
			$this->logFailure('Container view mass check failed.', $exception);

			return [];
		}

		$result = [];
		foreach ($parsedLinks as $link => $parsedLink)
		{
			$groupId = $parsedLink['groupId'];
			if (isset($states[$groupId]))
			{
				$state = $states[$groupId];
				$result[$link] = new WorkgroupSectionState(
					groupId: $state->groupId,
					isFound: $state->isFound,
					canView: $state->canView,
					isNewProject: $state->isNewProject,
					canonicalLink: $parsedLink['canonicalLink'],
				);
			}
		}

		return $result;
	}

	public function getStateByLink(int $userId, string $link): ?WorkgroupSectionState
	{
		return $this->getStatesByLinks($userId, [$link])[$link] ?? null;
	}

	protected function loadStates(int $userId, array $groupIds): array
	{
		if (
			!Loader::includeModule('socialnetwork')
			|| !method_exists(GroupAccessController::class, 'getAccessibleGroups')
		)
		{
			return [];
		}

		$rows = GroupAccessController::getAccessibleGroups($userId, GroupDictionary::VIEW, $groupIds);

		$result = [];
		foreach ($rows as $row)
		{
			$result[$row->groupId] = new WorkgroupSectionState(
				groupId: $row->groupId,
				isFound: $row->isFound,
				canView: $row->canView,
				isNewProject: $row->isNewProject,
			);
		}

		return $result;
	}

	protected function parseLink(string $link): ?array
	{
		return WorkgroupLink::parse($link);
	}

	private function logFailure(string $message, \Throwable $exception): void
	{
		$this->getLogger()?->error($message . "\n{exception}\n", ['exception' => $exception]);
	}

	private function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory(alwaysReturnLogger: false))->createById(
			self::LOGGER_ID,
			isCheckEnabledFromRegistry: false,
			returnDefaultLoggerIfNotExists: false,
		);
	}
}
