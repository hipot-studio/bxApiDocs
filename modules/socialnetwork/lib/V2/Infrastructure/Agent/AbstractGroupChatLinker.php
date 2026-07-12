<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Update\Stepper;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\ConvertChatService;
use Exception;

abstract class AbstractGroupChatLinker extends Stepper
{
	protected const LIMIT = 50;
	protected const MODE_LAST_ACTIVE = 'MODE_LAST_ACTIVE';
	protected const MODE_IN_ORDER = 'MODE_IN_ORDER';

	protected const MAX_RETRIES = 3;

	protected static $moduleId = 'socialnetwork';

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	public function execute(array &$option): bool
	{
		$groupId = $this->getGroupId();
		$groupChatId = $this->getGroupChatId();
		if ($groupId <= 0 || $groupChatId <= 0)
		{
			$this->deleteOption();

			return self::FINISH_EXECUTION;
		}

		$option = $this->getOption();
		$mode = $option['mode'] ?? self::MODE_LAST_ACTIVE;
		$lastId = (int)($option['lastId'] ?? 0);
		$retries = (int)($option['retries'] ?? 0);

		$chatIds = $this->getChatIds($groupId, $mode, $lastId);
		if (empty($chatIds))
		{
			$this->deleteOption();

			return self::FINISH_EXECUTION;
		}

		$chatService = $this->getConvertChatService();
		try
		{
			$result = $chatService->linkChatsToParent(array_values(array_filter($chatIds)), $groupChatId);

			if (!$result->isSuccess())
			{
				$this->logError($groupId, implode(';', $result->getErrorMessages()));
			}

			$isSuccess = $result->isSuccess();
		}
		catch (Exception $e)
		{
			$this->writeToLog($e);
			$isSuccess = false;
		}
		finally
		{
			$retries += 1;
		}

		$option['retries'] = $retries;
		if ($isSuccess || $retries >= self::MAX_RETRIES)
		{
			$option['lastId'] = $mode === self::MODE_IN_ORDER ? min(array_keys($chatIds)) : 0;
			$option['mode'] = self::MODE_IN_ORDER;
			$option['retries'] = 0;
		}

		$this->setOption($option);

		// base Stepper $option is not used, because there are options for every groupId for now
		$option = [];

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * @return array<int, int|null> entityId => ?chatId
	 * @throws LoaderException
	 * @throws ArgumentException
	 */
	abstract protected function getChatIds(int $groupId, string $mode, int $lastId): array;

	protected function logError(int $groupId, string $message): void
	{
		$linker = get_class($this);

		Logger::log(
			[
				'groupId' => $groupId,
				'message' => sprintf('%s: failed to update chats in group [%d]: %s', $linker, $groupId, $message),
			],
			'PROJECT_AI_CONVERSION',
		);
	}

	protected function getConvertChatService(): ConvertChatService
	{
		return Container::getInstance()->getConvertChatService();
	}

	protected function getOption(): array
	{
		$option = Option::get('main.stepper.' . static::getModuleId(), $this->getOptionName());

		if ($option !== '')
		{
			$option = unserialize($option, ['allowed_classes' => false]);
		}

		return is_array($option) ? $option : [];
	}

	protected function setOption(array $option): void
	{
		Option::set('main.stepper.' . static::getModuleId(), $this->getOptionName(), serialize($option));
	}

	protected function deleteOption(): void
	{
		Option::delete('main.stepper.' . static::getModuleId(), ['name' => $this->getOptionName()]);
	}

	private function getGroupId(): int
	{
		return (int)($this->getOuterParams()[0] ?? 0);
	}

	private function getGroupChatId(): int
	{
		return (int)($this->getOuterParams()[1] ?? 0);
	}

	private function getOptionName(): string
	{
		return static::class . "({$this->getGroupId()})";
	}
}
