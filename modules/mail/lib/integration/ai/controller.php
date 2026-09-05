<?php

namespace Bitrix\Mail\Integration\AI;

use Bitrix\Mail\Integration\AI\Context\Subject;
use Bitrix\Main\Event;
use Bitrix\Mail\Helper\Message\MessageThreadLoader;
use Bitrix\Mail\Helper\Message\MessageSearch;
use Bitrix\Main\Engine\CurrentUser;

final class Controller
{

	public static function onContextGetMessages(Event $event): array
	{
		$moduleId = $event->getParameter('module');
		$contextId = $event->getParameter('id');
		$contextParameters = $event->getParameter('params');

		if (!$moduleId || !$contextId)
		{
			return ['messages' => []];
		}

		if ($moduleId === 'mail' && Subject::isSubjectContext((string)$contextId))
		{
			return Subject::buildMessages(
				(string)$contextId,
				is_array($contextParameters) ? $contextParameters : [],
				(int)CurrentUser::get()->getId(),
			);
		}

		$isAddedQuote = filter_var(($contextParameters['isAddedQuote'] ?? null), FILTER_VALIDATE_BOOLEAN);
		$messageId = $contextParameters['messageId'];
		$messageIds = $contextParameters['messageIds'];

		if (!self::isNeededMailMessageContext($moduleId, $contextId, $isAddedQuote, $messageId, $messageIds))
		{
			return ['messages' => []];
		}

		// the client-supplied list serves only as an anchor: the branch is assembled server-side, so
		// ids the caller did not obtain legitimately never reach the ranking below
		$anchorId = (int)$messageId;
		if ($anchorId <= 0 && is_array($messageIds) && $messageIds)
		{
			$anchorId = (int)reset($messageIds);
		}

		if ($anchorId <= 0)
		{
			return ['messages' => []];
		}

		$messageThreadLoader = new MessageThreadLoader($anchorId);
		$messageThreadLoader->loadThreadBranchMessageIds();
		$messageIds = $messageThreadLoader->getThreadMessageIds();

		if (!$messageIds)
		{
			return ['messages' => []];
		}

		return self::loadMessages($messageIds);
	}

	private static function isNeededMailMessageContext(
		string $moduleId,
		string $contextId,
		bool $isAddedQuote,
		?int $messageId = null,
		?array $messageIds = null,
	): bool
	{
		if ($moduleId !== 'mail' || $isAddedQuote !== false)
		{
			return false;
		}

		if (!str_starts_with($contextId, 'mail_reply') && !str_starts_with($contextId, 'crm_mail_reply'))
		{
			return false;
		}

		if ($messageId < 0 && empty($messageIds))
		{
			return false;
		}

		return true;
	}

	/**
	 * @param int[] $messageIds
	 * @return array
	 */
	private static function loadMessages(array $messageIds): array
	{
		$messageIds = array_filter($messageIds, function ($item) {
			return filter_var($item, FILTER_VALIDATE_INT) !== false;
		});

		if (empty($messageIds))
		{
			return ['messages' => []];
		}

		$userId = (int)CurrentUser::get()->getId();

		$messageSearch = new MessageSearch();
		$latestMessageId = $messageSearch->getLatestVisibleMessageId($messageIds, $userId) ?? (int)end($messageIds);

		$content = $messageSearch->getReplyContextBody(
			$latestMessageId,
			$userId,
		);

		if ($content === null)
		{
			return ['messages' => []];
		}

		return [
			'messages' => [
				['content' => $content],
			],
		];
	}
}
