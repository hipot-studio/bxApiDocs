<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Trigger\Dto\ChatContextDto;
use Bitrix\AiAssistant\Trigger\Service\TriggerExecutor;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Copilot\CopilotTitle;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Trigger\Action\AiSiteChatTrigger;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * Publishes the server messages of landing into the existing chat of a site row.
 *
 * The notice is registered for the key of the chat for the time of one trigger call with existingMessage,
 * the trigger action reads it from the registry. The message goes only into a chat that exists: a deleted
 * chat, a user who left it, an unavailable bot or switched off triggers skip the message - no new chat is
 * created and the caller is never interrupted; the skip is written to the journal and to the event log.
 *
 * The chat the site was created from was made before the site and carries the default title: after the
 * message «Site created» it is renamed after the site, best effort.
 */
class AiSiteChatNoticeService
{
	public const EVENT_SENT = 'notice_sent';
	public const EVENT_SKIPPED = 'notice_skipped';
	public const EVENT_TITLE_SKIPPED = 'title_skipped';

	public const EVENT_LOG_AUDIT_TYPE_SKIPPED = 'LANDING_AI_SITE_CHAT_NOTICE_SKIPPED';

	private readonly MarkupLifecycleLog $log;
	private readonly AiSiteChatSiteTitleProvider $siteTitleProvider;
	private readonly AiSiteBindingService $bindingService;

	public function __construct(
		?MarkupLifecycleLog $log = null,
		?AiSiteChatSiteTitleProvider $siteTitleProvider = null,
		?AiSiteBindingService $bindingService = null,
	)
	{
		$this->log = $log ?? new MarkupLifecycleLog();
		$this->siteTitleProvider = $siteTitleProvider ?? new AiSiteChatSiteTitleProvider();
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
	}

	/**
	 * Publishes the message into the chat of the given key, bare or markup.
	 *
	 * @return bool True when the message was published.
	 */
	public function notify(int $userId, string $entityId, AiSiteChatNotice $notice): bool
	{
		$registered = false;

		try
		{
			if ($userId <= 0 || $this->parseBindingId($entityId) <= 0)
			{
				throw new \InvalidArgumentException('The notice needs the owner and the key of the chat.');
			}
			if (!$this->includeAiAssistantModule())
			{
				throw new \RuntimeException('The aiassistant module is not installed.');
			}

			AiSiteChatNoticeRegistry::put($entityId, $notice);
			$registered = true;

			$result = $this->createExecutor()->execute(
				AiSiteChatBindingContract::TRIGGER_CODE,
				$userId,
				$entityId,
				new ChatContextDto(entityId: $entityId, existingMessage: $notice->type),
			);
		}
		catch (\Throwable $throwable)
		{
			$this->logSkipped($userId, $entityId, $notice, $this->resolveErrorCode($throwable));

			return false;
		}
		finally
		{
			if ($registered)
			{
				AiSiteChatNoticeRegistry::remove($entityId);
			}
		}

		$this->log->write(self::EVENT_SENT, $this->buildLogFields($userId, $entityId, $notice));

		if ($notice->type === AiSiteChatNotice::TYPE_CREATED)
		{
			$this->renameChatAfterSite($userId, $entityId, $notice, $result->chatId);
		}

		return true;
	}

	/**
	 * Publishes the message into the existing chat of the row. A row may have a chat under each of its keys,
	 * so the key is chosen by the chats the owner of the row has. Without a chat under any key, or when the
	 * chats cannot be read, the preferred key is used: aiassistant refuses a key without a living chat and
	 * creates none, so the message is skipped.
	 *
	 * @param array{ID: int|string, USER_ID: int|string} $row
	 */
	public function notifyBinding(array $row, AiSiteChatNotice $notice): bool
	{
		$userId = (int)($row['USER_ID'] ?? 0);
		$bindingId = (int)($row['ID'] ?? 0);
		if ($userId <= 0 || $bindingId <= 0)
		{
			return $this->notify($userId, $bindingId > 0 ? (string)$bindingId : '', $notice);
		}

		$candidates = [];
		$entityId = null;
		try
		{
			$candidates = $this->getChatEntityIdCandidates($bindingId, $userId, $notice);
			// a single key is the preferred one whatever the chats are, so the map is not read for it
			if (count($candidates) > 1)
			{
				$entityId = $this->bindingService->findFirstMappedChatEntityId($userId, $candidates);
			}
		}
		catch (\Throwable)
		{
			// the executor decides on the preferred key below
		}

		$entityId ??= $candidates[0] ?? $this->bindingService->getChatEntityId($bindingId, false);

		return $this->notify($userId, $entityId, $notice);
	}

	protected function includeAiAssistantModule(): bool
	{
		return Loader::includeModule('aiassistant');
	}

	/**
	 * Taken from the service locator on every call: tests substitute the executor there.
	 */
	protected function createExecutor(): TriggerExecutor
	{
		return ServiceLocator::getInstance()->get(TriggerExecutor::class);
	}

	/**
	 * Gives the chat the title of its site. A title the user set in the chat is kept.
	 *
	 * @throws \Throwable when the chat cannot be renamed
	 */
	protected function renameChat(int $chatId, string $title): void
	{
		if (!Loader::includeModule('im'))
		{
			throw new \RuntimeException('The im module is not installed.');
		}

		$chat = Chat::getInstance($chatId);
		if ((int)$chat->getChatId() !== $chatId)
		{
			throw new \RuntimeException('The chat is not found.');
		}
		if ($chat instanceof CopilotChat && (new CopilotTitle($chatId))->isCustom())
		{
			return;
		}
		if ($chat->getTitle() === $title)
		{
			return;
		}

		$result = $chat->setTitle($title)->save();
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}
	}

	/**
	 * The file journal is off by default, so a skipped message is also visible in the event log.
	 */
	protected function addEventLogWarning(array $fields): void
	{
		if (class_exists(\CEventLog::class))
		{
			\CEventLog::Add($fields);
		}
	}

	/**
	 * Keys of the row to address the message by, the preferred first.
	 *
	 * «Site created» and «Could not create the site» come from the markup generation only: its chat is the
	 * markup one, or the bare one of a tab that still held the bare key. The rule of the editor does not fit
	 * them, as a failed work is no longer accepted on the row. «Site deleted» goes where the editor of the
	 * site opens the chat of the row.
	 *
	 * @return list<string>
	 */
	private function getChatEntityIdCandidates(int $bindingId, int $userId, AiSiteChatNotice $notice): array
	{
		if ($notice->type === AiSiteChatNotice::TYPE_DELETED)
		{
			return [$this->bindingService->getAcceptedWorkChatEntityId($bindingId, $userId)];
		}

		return [
			$this->bindingService->getChatEntityId($bindingId, true),
			$this->bindingService->getChatEntityId($bindingId, false),
		];
	}

	private function logSkipped(int $userId, string $entityId, AiSiteChatNotice $notice, string $errorCode): void
	{
		$fields = $this->buildLogFields($userId, $entityId, $notice);
		$fields['errorCode'] = $errorCode;
		$this->log->write(self::EVENT_SKIPPED, $fields);

		try
		{
			$this->addEventLogWarning([
				'SEVERITY' => 'WARNING',
				'AUDIT_TYPE_ID' => self::EVENT_LOG_AUDIT_TYPE_SKIPPED,
				'MODULE_ID' => 'landing',
				'ITEM_ID' => $entityId,
				'DESCRIPTION' => implode(PHP_EOL, [
					'AI site chat notice was skipped.',
					'TYPE: ' . $notice->type,
					'ENTITY_ID: ' . $entityId,
					'USER_ID: ' . $userId,
					'ERROR_CODE: ' . $fields['errorCode'],
				]),
			]);
		}
		catch (\Throwable)
		{
			// the event log must not change the outcome of the caller
		}
	}

	/**
	 * The row has its site by now: the message «Site created» follows the pinning of the site. The owner of
	 * the row is named explicitly, as the generation may finish without a current user.
	 */
	private function renameChatAfterSite(int $userId, string $entityId, AiSiteChatNotice $notice, int $chatId): void
	{
		try
		{
			$siteTitle = $this->siteTitleProvider->findByEntityIdForUser($entityId, $userId);
			$chatTitle = $siteTitle === null ? null : AiSiteChatTrigger::buildChatTitle($siteTitle);
			if ($chatTitle === null || $chatId <= 0)
			{
				return;
			}

			$this->renameChat($chatId, $chatTitle);
		}
		catch (\Throwable $throwable)
		{
			$fields = $this->buildLogFields($userId, $entityId, $notice);
			$fields['chatId'] = $chatId;
			$fields['errorCode'] = $this->resolveErrorCode($throwable);
			$this->log->write(self::EVENT_TITLE_SKIPPED, $fields);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildLogFields(int $userId, string $entityId, AiSiteChatNotice $notice): array
	{
		return [
			'bindingId' => $this->parseBindingId($entityId),
			'userId' => $userId,
			'type' => $notice->type,
			'entityId' => $entityId,
		];
	}

	/**
	 * The row named by the key of either generation; 0 for a string that is not a key of a row.
	 */
	private function parseBindingId(string $entityId): int
	{
		return (int)($this->bindingService->parseChatEntityId($entityId)['bindingId'] ?? 0);
	}

	private function resolveErrorCode(\Throwable $throwable): string
	{
		if (method_exists($throwable, 'getErrorCode'))
		{
			$code = $throwable->getErrorCode();
			if (is_string($code) && $code !== '')
			{
				return $code;
			}
		}

		$class = get_class($throwable);
		$position = strrpos($class, '\\');

		return $position === false ? $class : substr($class, $position + 1);
	}
}
