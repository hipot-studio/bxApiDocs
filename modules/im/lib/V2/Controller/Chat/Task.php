<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AI\TaskCreation\CreationError;
use Bitrix\Im\V2\Integration\AI\TaskCreation\CreatorFactory;
use Bitrix\Im\V2\Link\Task\TaskService;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Message\MessageService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\RestError;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

class Task extends BaseController
{
	/**
	 * How many ids the request carried. The collection can be shorter (deleted messages, foreign
	 * chats), so both the selection limit and the AI context window must judge the request itself.
	 *
	 * Zero means no binder of this controller counted them: a request that names the ids under
	 * another key is served by an inherited binder, @see self::resolveRequestedMessagesCount().
	 */
	private int $requestedMessagesCount = 0;

	public function configureActions()
	{
		$config = parent::configureActions();

		// createByAi changes state: it registers a dedup record and queues an AI job, so GET - which
		// the default prefilters of the platform allow next to POST - has no business here
		$config['createByAi'] = [
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];

		return $config;
	}

	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				MessageCollection::class,
				'messages',
				function($className, array $ids) {
					$this->requestedMessagesCount = count($ids);

					// an early refusal of an oversized selection, so that the collection of it is
					// never built; the limit itself is enforced by the action, which sees every
					// selection regardless of the binder that assembled it
					if ($this->exceedsSelectionLimit($this->requestedMessagesCount))
					{
						$this->addError(new MessageError(MessageError::TOO_MANY_MESSAGES));

						return null;
					}

					return $this->getMessagesByIds($ids);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod im.v2.Chat.Task.prepare
	 */
	public function prepareAction(?Chat $chat = null, ?\Bitrix\Im\V2\Message $message = null): ?array
	{
		if (isset($message)) {
			$chat = $message->getChat();
			if (!$chat->checkAccess()->isSuccess())
			{
				$this->addError(new RestError(RestError::ACCESS_ERROR));

				return null;
			}
		}

		if (!isset($chat))
		{
			$this->addError(new Chat\ChatError(Chat\ChatError::TASK_ACCESS_ERROR));

			return null;
		}

		$taskService = new TaskService();
		$result = $taskService->prepareDataForCreateSlider($chat, $message);
		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0];
			if (isset($error))
			{
				$this->addError($error);

				return null;
			}
		}

		return [
			'link' => $result->getResult()['LINK'],
			'params' => $result->getResult()['PARAMS']
		];
	}

	/**
	 * The selection has a default so that a request without ids reaches this body: without one the
	 * binder of the platform has nothing to fall back on and answers with an exception of its own,
	 * naming an internal class instead of the refusal the clients know.
	 *
	 * @restMethod im.v2.Chat.Task.createByAi
	 */
	public function createByAiAction(?MessageCollection $messages = null): ?array
	{
		if (!Features::isAiTaskCreationFromMessagesAvailable())
		{
			$this->addError(new CreationError(CreationError::AI_TASK_CREATION_NOT_AVAILABLE));

			return null;
		}

		if (!(new Restriction())->isAiTaskCreationActive())
		{
			$this->addError(new CreationError(CreationError::AI_TASK_CREATION_NOT_AVAILABLE));

			return null;
		}

		if ($messages === null || $messages->isEmpty())
		{
			// a refusing binder returns null too, having already named its own reason - the request
			// is empty only when nothing has been said about the selection yet
			if (!$this->hasErrors())
			{
				$this->addError(new MessageError(MessageError::EMPTY_MESSAGE));
			}

			return null;
		}

		$requestedMessagesCount = $this->resolveRequestedMessagesCount($messages);

		if ($this->exceedsSelectionLimit($requestedMessagesCount))
		{
			$this->addError(new MessageError(MessageError::TOO_MANY_MESSAGES));

			return null;
		}

		$taskCreator = (new CreatorFactory())->createByMessages($messages, $requestedMessagesCount);
		$result = $taskCreator->sendAiQuery();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * The selection limit and the AI context window are both properties of the request, but only
	 * the binder of this controller counts the ids it carried. A request that names them under
	 * another key is served by an inherited binder (@see BaseController::getAutoWiredParameters()),
	 * which counts nothing at all, so there the collection it produced is the only measure left.
	 */
	private function resolveRequestedMessagesCount(MessageCollection $messages): int
	{
		return $this->requestedMessagesCount > 0 ? $this->requestedMessagesCount : $messages->count();
	}

	private function exceedsSelectionLimit(int $messagesCount): bool
	{
		return $messagesCount > MessageService::getMultipleActionMessageLimit();
	}
}
