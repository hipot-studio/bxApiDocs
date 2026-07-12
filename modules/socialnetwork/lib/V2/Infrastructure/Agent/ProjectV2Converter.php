<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\Update\Stepper;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\ConvertService;
use Throwable;

class ProjectV2Converter extends Stepper
{
	private const LIMIT = 5;
	private const MAX_RETRIES = 3;

	private const CONVERTIBLE_TYPES = [
		Type::Group,
		Type::Project,
		Type::Collab,
	];

	protected static $moduleId = 'socialnetwork';

	private array $option;

	public function execute(array &$option): bool
	{
		$this->option = &$option;

		$groupsToConvert = $this->getGroupsToConvert();
		if (empty($groupsToConvert))
		{
			return self::FINISH_EXECUTION;
		}

		$failedGroups = $this->convertGroups($groupsToConvert);
		$this->setFailedGroups($failedGroups);

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * @return array<int, int> groupId => retries
	 */
	private function getGroupsToConvert(): array
	{
		$batch = $this->getFailedGroups();

		$newLimit = self::LIMIT - count($batch);
		$lastId = $this->getLastId();
		if ($newLimit > 0)
		{
			$newRows = $this->getNewGroups($newLimit);
			foreach ($newRows as $row)
			{
				$id = (int)$row['ID'];
				$batch[$id] = 0;
				$lastId = max($lastId, $id);
			}
		}

		$this->setLastId($lastId);
		return $batch;
	}

	protected function getNewGroups(int $limit = self::LIMIT): array
	{
		$convertibleTypes = array_map(
			static fn(Type $type): string => $type->value,
			self::CONVERTIBLE_TYPES,
		);

		return Container::getInstance()
			->getProjectRepository()
			->getGroupIdsByTypes($convertibleTypes, $this->getLastId(), $limit)
		;
	}

	/**
	 * @param array<int, int> $groupsToConvert groupId => retries
	 */
	private function convertGroups(array $groupsToConvert): array
	{
		$failedGroups = [];
		foreach ($groupsToConvert as $groupId => $retries)
		{
			if ($this->tryConvertGroup($groupId))
			{
				continue;
			}

			$newRetries = $retries + 1;
			if ($newRetries < self::MAX_RETRIES)
			{
				$failedGroups[$groupId] = $newRetries;
			}
			else
			{
				$this->logError($groupId, 'Max retries reached, skipping group');
			}
		}

		return $failedGroups;
	}

	private function tryConvertGroup(int $groupId): bool
	{
		try
		{
			$result = $this->getConvertService()->convert($groupId, $this->getInitiatorId());

			if ($result->isSuccess())
			{
				return true;
			}

			$this->logError($groupId, implode(', ', $result->getErrorMessages()));
		}
		catch (Throwable $e)
		{
			$this->logError($groupId, $e->getMessage());
		}

		return false;
	}

	/**
	 * @return array<int, int> groupId => triesCount
	 */
	private function getFailedGroups(): array
	{
		return (array)($this->option['failedGroups'] ?? []);
	}

	protected function getConvertService(): ConvertService
	{
		return Container::getInstance()->getConvertService();
	}

	private function getLastId(): int
	{
		return (int)($this->option['lastId'] ?? 0);
	}

	private function setLastId(int $id): void
	{
		$this->option['lastId'] = $id;
	}

	private function setFailedGroups(array $failedGroups): void
	{
		$this->option['failedGroups'] = $failedGroups;
	}

	private function getInitiatorId(): int
	{
		return (int)($this->option['initiatorId'] ?? 1);
	}

	protected function logError(int $groupId, string $message): void
	{
		Logger::log(
			[
				'groupId' => $groupId,
				'message' => sprintf('ProjectV2Converter: failed to convert group [%d]: %s', $groupId, $message),
			],
			'PROJECT_AI_CONVERSION',
		);
	}
}

