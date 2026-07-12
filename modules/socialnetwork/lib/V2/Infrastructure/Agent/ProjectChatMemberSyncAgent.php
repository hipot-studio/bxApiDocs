<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Update\Stepper;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Public\Command\Project\SyncProjectChatMembersCommand;
use Bitrix\Socialnetwork\V2\Public\Command\Project\Result\SyncProjectChatMembersResult;
use Throwable;

class ProjectChatMemberSyncAgent extends Stepper
{
	private const LIMIT = 20;

	protected static $moduleId = 'socialnetwork';

	public function execute(array &$option): bool
	{
		$groupId = $this->getGroupId();
		$chatId = $this->getChatId();

		if ($groupId <= 0 || $chatId <= 0)
		{
			return $this->finishExecution();
		}

		$concreteOption = $this->getConcreteOption();
		$lastAddUserId = (int)($concreteOption['lastAddUserId'] ?? 0);
		$lastDeleteUserId = (int)($concreteOption['lastDeleteUserId'] ?? 0);

		try
		{
			$result = $this->runSyncCommand(
				groupId: $groupId,
				chatId: $chatId,
				lastAddUserId: $lastAddUserId,
				lastDeleteUserId: $lastDeleteUserId,
			);
		}
		catch (Throwable $exception)
		{
			$this->logError($groupId, $exception->getMessage());

			return $this->finishExecution();
		}

		if (!$result->isSuccess())
		{
			$this->logError($groupId, implode(', ', $result->getErrorMessages()));
		}

		$concreteOption['lastAddUserId'] = $result->getLastAddUserId();
		$concreteOption['lastDeleteUserId'] = $result->getLastDeleteUserId();

		if ($result->hasMore())
		{
			return $this->continueExecution(payloadToSave: $concreteOption);
		}

		return $this->finishExecution();
	}

	protected function runSyncCommand(
		int $groupId,
		int $chatId,
		int $lastAddUserId,
		int $lastDeleteUserId,
	): SyncProjectChatMembersResult
	{
		/** @var SyncProjectChatMembersResult $result */
		$result = (new SyncProjectChatMembersCommand(
			projectId: $groupId,
			chatId: $chatId,
			chunkSize: self::LIMIT,
			lastAddUserId: $lastAddUserId,
			lastDeleteUserId: $lastDeleteUserId,
		))->run();

		return $result;
	}

	protected function logError(int $groupId, string $message): void
	{
		Logger::log(
			[
				'groupId' => $groupId,
				'message' => sprintf(
					'ProjectChatMemberSyncAgent: failed to sync project chat members in group [%d]: %s',
					$groupId,
					$message,
				),
			],
			'PROJECT_AI_CONVERSION',
		);
	}

	private function getGroupId(): int
	{
		return (int)($this->getOuterParams()[0] ?? 0);
	}

	private function getChatId(): int
	{
		return (int)($this->getOuterParams()[1] ?? 0);
	}

	private function continueExecution(array $payloadToSave): bool
	{
		$this->setConcreteOption($payloadToSave);

		return self::CONTINUE_EXECUTION;
	}

	private function finishExecution(): bool
	{
		$this->deleteConcreteOption();

		return self::FINISH_EXECUTION;
	}

	protected function getConcreteOption(): array
	{
		$concreteOption = Option::get(
			moduleId: $this->getOptionModuleId(),
			name: $this->getOptionName(),
		);

		if ($concreteOption !== '')
		{
			$concreteOption = unserialize($concreteOption, ['allowed_classes' => false]);
		}

		$concreteOption = is_array($concreteOption) ? $concreteOption : [];

		return $concreteOption;
	}

	protected function setConcreteOption(array $concreteOption): void
	{
		Option::set(
			moduleId: $this->getOptionModuleId(),
			name: $this->getOptionName(),
			value: serialize($concreteOption),
		);
	}

	protected function deleteConcreteOption(): void
	{
		Option::delete(
			moduleId: $this->getOptionModuleId(),
			filter: ['name' => $this->getOptionName()],
		);
	}

	private function getOptionModuleId(): string
	{
		return 'main.stepper.' . static::getModuleId();
	}

	private function getOptionName(): string
	{
		return static::class . "({$this->getGroupId()})";
	}
}
