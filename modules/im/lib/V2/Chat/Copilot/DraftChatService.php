<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\Im\Model\ChatParamTable;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Add\AddResult;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class DraftChatService
{
	protected const LOCK_TIMEOUT = 3;

	public function getOrCreate(int $userId): AddResult
	{
		// Availability must be re-checked on the reuse path too: CopilotChat::add()
		// validates it only when creating, so without this a draft created earlier
		// would still be returned after imbot/AI/copilot-bot became unavailable.
		$availability = CopilotChat::checkCopilotAvailability();
		if (!$availability->isSuccess())
		{
			return (new AddResult())->addErrors($availability->getErrors());
		}

		// Hit path: reuse an existing draft without taking the lock. The reuse path
		// performs no write, so concurrent Draft.get calls for the same user need not
		// serialize and pay the lock/unlock round-trips on every new-chat page open.
		$chatId = $this->findExistingDraftChatId($userId);
		if ($chatId !== null)
		{
			return (new AddResult())->setChat(Chat::getInstance($chatId));
		}

		// Miss path: serialize concurrent creation per user. Without the lock two
		// parallel calls can both miss the existing draft and create a second hidden
		// copilot chat that is never surfaced again (ORDER BY ID ASC keeps the oldest).
		// Fail-fast on lock contention mirrors CommentChat::create() and
		// ChatFactory::addUniqueChatInternal(): proceeding without the lock would
		// re-introduce the very duplicate-draft race the lock exists to prevent.
		$connection = Application::getConnection();
		$lockName = $this->getLockName($userId);

		$isLocked = $connection->lock($lockName, self::LOCK_TIMEOUT);
		if (!$isLocked)
		{
			return (new AddResult())->addError(new ChatError(ChatError::CREATION_ERROR));
		}

		try
		{
			// Re-check inside the critical section: another request may have created
			// the draft between the unlocked lookup above and acquiring the lock.
			$chatId = $this->findExistingDraftChatId($userId);
			if ($chatId !== null)
			{
				return (new AddResult())->setChat(Chat::getInstance($chatId));
			}

			$context = new Context();
			$context->setUserId($userId);

			return (new CopilotChat(context: $context))->add(
				[
					'SKIP_ADD_MESSAGE' => 'Y',
					'SKIP_ANALYTICS' => 'Y',
					'CHAT_PARAMS' => [
						[
							'PARAM_NAME' => Params::IS_COPILOT_DRAFT,
							'PARAM_VALUE' => true,
						],
					],
				],
				$context
			);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function getLockName(int $userId): string
	{
		return 'im_copilot_draft_create_' . $userId;
	}

	private function findExistingDraftChatId(int $userId): ?int
	{
		$row = ChatTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				new Reference(
					'DRAFT_PARAM',
					ChatParamTable::class,
					Join::on('this.ID', 'ref.CHAT_ID')
						->where('ref.PARAM_NAME', Params::IS_COPILOT_DRAFT),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->where('AUTHOR_ID', $userId)
			->where('TYPE', Chat::IM_TYPE_COPILOT)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : null;
	}
}
