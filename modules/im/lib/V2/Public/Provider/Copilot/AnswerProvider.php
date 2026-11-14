<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Provider\Copilot;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AnswerProvider
{
	public function __construct(
		private readonly int $copilotBotId,
	)
	{
	}

	/**
	 * Reads the final reply to a question owned by the authenticated user.
	 *
	 * @return Result Data contains status and, when completed, a message with id, raw text and nullable blocks.
	 */
	public function get(int $userId, int $messageId): Result
	{
		if ($userId <= 0 || $messageId <= 0)
		{
			return $this->accessDenied();
		}

		if ($this->copilotBotId <= 0)
		{
			return $this->accessDenied();
		}

		$question = new Message($messageId);
		if (
			$question->getAuthorId() !== $userId
			|| !($question->getChat() instanceof CopilotChat)
			|| !$question->checkAccess($userId)->isSuccess()
		)
		{
			return $this->accessDenied();
		}

		$replyId = $this->findReplyId($question);
		if ($replyId !== null)
		{
			$reply = new Message($replyId);
			if ($this->isReadableReply($reply, $userId))
			{
				if ($this->isErrorReply($reply))
				{
					return (new Result())->setData(['status' => 'failed']);
				}

				return (new Result())->setData([
					'status' => 'completed',
					'message' => [
						'id' => $reply->getId(),
						'text' => (string)$reply->getMessage(),
						'blocks' => $reply->getBlocksBuilder()?->toArray(),
					],
				]);
			}
		}

		return (new Result())->setData([
			'status' => $question->getParams()->get(Params::COPILOT_GENERATION_STOPPED)->getValue()
				? 'stopped'
				: 'pending',
		]);
	}

	private function findReplyId(Message $question): ?int
	{
		$row = MessageParamTable::query()
			->setSelect(['MESSAGE_ID'])
			->where('PARAM_NAME', Params::COPILOT_TRIGGER_MESSAGE_ID)
			->where('PARAM_VALUE', (string)$question->getId())
			->where('MESSAGE.CHAT_ID', $question->getChatId())
			->where('MESSAGE.AUTHOR_ID', $this->copilotBotId)
			->setOrder(['MESSAGE_ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['MESSAGE_ID'] : null;
	}

	private function isReadableReply(Message $reply, int $userId): bool
	{
		$params = $reply->getParams();
		$componentParams = $params->get(Params::COMPONENT_PARAMS)->getValue();

		return $reply->checkAccess($userId)->isSuccess()
			&& !$reply->isSystem()
			&& !$params->get(Params::IS_DELETED)->getValue()
			&& empty($componentParams['COPILOT_PROCESS']);
	}

	private function isErrorReply(Message $reply): bool
	{
		$params = $reply->getParams();
		$componentParams = $params->get(Params::COMPONENT_PARAMS)->getValue();

		return !empty($componentParams['COPILOT_ERROR'])
			|| $params->get(Params::COMPONENT_ID)->getValue() === 'ErrorMessage';
	}

	private function accessDenied(): Result
	{
		return (new Result())->addError(new Error('Question is unavailable.', 'ACCESS_DENIED'));
	}
}
