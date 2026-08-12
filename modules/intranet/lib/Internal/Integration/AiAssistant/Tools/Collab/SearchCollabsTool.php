<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\SearchCollabsDto;
use Bitrix\Intranet\Internal\Integration\Socialnetwork\CollabRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Collab\Access\CollabAccessController;
use Bitrix\Socialnetwork\Collab\Access\CollabDictionary;

class SearchCollabsTool extends BaseCollabTool
{
	public function getName(): string
	{
		return 'search_collabs';
	}

	public function getDescription(): string
	{
		return
			'Searches collab or projects by name and returns their projectId values. '
			. 'Use this tool to resolve a collab projectId before calling send_invitations or invite-link tools. '
			. 'Returns only collabs (projects) where the current user can manage guest invitations and where guest invitations are enabled. '
			. 'Supports pagination via limit and offset. '
			. 'If several collabs match, ask the user to choose before continuing.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectName' => [
					'type' => 'string',
					'description' => 'Collab project name or a part of it.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'description' => 'Maximum number of collabs to return. Default is 20.',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Pagination offset. Default is 0.',
				],
			],
			'required' => ['projectName'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredInternal(int $userId, ...$args): array
	{
		$dto = SearchCollabsDto::fromArray($args);

		if (!$this->isSocialnetworkModuleLoaded())
		{
			throw new McpException('Socialnetwork module is not available.');
		}

		$page = $this->getCollabPage($userId, $dto);

		return [
			'collabs(projects)' => $page['projects'],
			'limit' => $dto->limit,
			'offset' => $dto->offset,
			'hasMore' => $page['hasMore'],
		];
	}

	protected function isSocialnetworkModuleLoaded(): bool
	{
		return Loader::includeModule('socialnetwork');
	}

	protected function getCollabPage(int $userId, SearchCollabsDto $dto): array
	{
		$cache = Application::getInstance()->getManagedCache();
		$cacheKey = $this->getCacheKey($userId, $dto);
		$cacheDir = self::getCacheDir();
		$cacheTtl = 86400;

		if ($cache->read($cacheTtl, $cacheKey, $cacheDir))
		{
			return $cache->get($cacheKey);
		}

		$page = $this->buildCollabPage($userId, $dto);
		$cache->set($cacheKey, $page);

		return $page;
	}

	protected function buildCollabPage(int $userId, SearchCollabsDto $dto): array
	{
		$projects = [];
		$rawOffset = 0;
		$batchSize = 50;
		$skippedCount = 0;
		$acceptedCount = 0;
		$requestedCount = $dto->limit + 1;

		do
		{
			$batch = $this->getCollabsByName($userId, $dto->projectName, $batchSize, $rawOffset);

			foreach ($batch as $project)
			{
				$projectId = (int)($project['id'] ?? 0);
				if (
					$projectId <= 0
					|| ($project['isAllowedGuestsInvitation'] ?? false) !== true
					|| !$this->canInviteToProject($userId, $projectId)
				)
				{
					continue;
				}

				if ($skippedCount < $dto->offset)
				{
					$skippedCount++;

					continue;
				}

				$projects[] = [
					'projectId' => $projectId,
					'name' => (string)($project['name'] ?? ''),
				];
				$acceptedCount++;

				if ($acceptedCount >= $requestedCount)
				{
					break 2;
				}
			}

			$rawOffset += count($batch);
		}
		while (count($batch) === $batchSize);

		$hasMore = count($projects) > $dto->limit;
		if ($hasMore)
		{
			array_pop($projects);
		}

		return [
			'userId' => $userId,
			'projectName' => $dto->projectName,
			'projects' => $projects,
			'hasMore' => $hasMore,
		];
	}

	protected function getCollabsByName(int $userId, string $projectName, int $limit, int $offset): array
	{
		return $this->collabRepository->getCollabsByName(
			$userId,
			$projectName,
			$limit,
			$offset,
		);
	}

	protected function canInviteToProject(int $userId, int $projectId): bool
	{
		return CollabAccessController::can($userId, CollabDictionary::INVITE, $projectId);
	}

	protected function getCacheKey(int $userId, SearchCollabsDto $dto): string
	{
		return date('dmy') . md5(
			$userId
			. '|' . trim($dto->projectName)
			. '|' . $dto->limit
			. '|' . $dto->offset
		);
	}

	public static function getCacheDir(): string
	{
		return 'intranet_aiassistant/search_collabs';
	}
}
