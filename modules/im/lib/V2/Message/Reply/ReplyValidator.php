<?php

namespace Bitrix\Im\V2\Message\Reply;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Result;

/**
 * Policy: validates that a reply reference is valid for the given target chat.
 *
 * Contract TPL-01:
 *   - validate(int $replyId, Chat $targetChat): Result
 *   - replyId <= 0  → success, empty result (reply absent — valid)
 *   - no access to original → addErrors(checkAccess()->getErrors())
 *   - original.chat !== targetChat → MessageError::REPLY_ERROR
 *   - success → getResult()['REPLY_ID'] = int
 *
 * Invariants:
 *   - Does NOT write to storage and does NOT mutate messages.
 *   - Single source of truth for reply-link validation (no duplication allowed elsewhere).
 */
class ReplyValidator
{
	use ContextCustomer;

	/**
	 * Validates that $replyId refers to a message in $targetChat that the current user can access.
	 *
	 * @param int  $replyId    ID of the original message being replied to.
	 * @param Chat $targetChat The chat in which the reply will be posted.
	 * @return Result Success with ['REPLY_ID' => int] when valid; success with empty data when $replyId <= 0;
	 *                failure with errors when access is denied or chats differ.
	 */
	public function validate(int $replyId, Chat $targetChat): Result
	{
		$result = new Result();

		if ($replyId <= 0)
		{
			return $result;
		}

		// checkAccess()/getChat() need only ID, CHAT_ID, DATE_CREATE — avoid loading heavy MESSAGE text on the reply hot path
		$originalFields = MessageTable::query()
			->setSelect(['ID', 'CHAT_ID', 'DATE_CREATE'])
			->where('ID', $replyId)
			->setLimit(1)
			->fetch()
		;
		$original = new Message($originalFields ?: null);
		$original->setContext($this->getContext());

		$accessResult = $original->checkAccess();
		if (!$accessResult->isSuccess())
		{
			return $result->addErrors($accessResult->getErrors());
		}

		if ($original->getChat()->getId() !== $targetChat->getId())
		{
			return $result->addError(new MessageError(
				MessageError::REPLY_ERROR,
				'You can only reply to a message within the same chat'
			));
		}

		$result->setResult(['REPLY_ID' => $original->getId()]);

		return $result;
	}
}
