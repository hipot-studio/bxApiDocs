<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest\Controller;

use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\Message\MessageActions;
use Bitrix\Mail\Infrastructure\Rest\Dto\MessageDto;
use Bitrix\Mail\Infrastructure\Rest\RequestParams;
use Bitrix\Mail\Helper\Message\MessageSearch;
use Bitrix\Mail\Helper\RecipientHelper;
use Bitrix\Mail\Helper\Message\MessageSender;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * REST controller for mail message operations.
 */
#[DtoType(MessageDto::class)]
class Message extends RestController
{
	private const MAX_LIMIT = 200;

	/**
	 * Endpoint: mail.message.get
	 *
	 * Returns a single message by ID with full content.
	 *
	 * @restMethod mail.message.get
	 * @param GetRequest $request id* — message identifier
	 * @return GetResponse {result: MessageDto}
	 */
	public function getAction(GetRequest $request): GetResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$messageId = (int)$request->id;

		$provider = new MessageSearch();

		try
		{
			$message = $provider->getMessageById($messageId, $userId);
		}
		catch (SystemException)
		{
			throw new EntityNotFoundException($messageId);
		}

		return new GetResponse($this->mapMessageToDto($message));
	}

	/**
	 * Endpoint: mail.message.list
	 *
	 * Searches messages across user's mailboxes.
	 * Uses REST V3 request structure (filter + pagination) with custom filter fields,
	 * because mail search is not ORM-backed.
	 *
	 * @restMethod mail.message.list
	 * Request params:
	 * - filter:
	 *   - int    mailboxId       Filter by mailbox ID
	 *   - string searchQuery     Full-text search query
	 *   - string dateFrom        Start date (Y/m/d H:i)
	 *   - string dateTo          End date (Y/m/d H:i)
	 *   - bool   isSeen          Read/unread filter
	 *   - bool   hasAttachments  Has attachments filter
	 *   - string folder          Folder name or path
	 * - pagination:
	 *   - int limit   Results per page (1-100, default 25)
	 *   - int offset  Offset from start
	 *
	 * @return ListResponse {result: MessageDto[]}
	 */
	public function listAction(ListRequest $request): ListResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$limit = min($request->pagination?->getLimit() ?? SearchMessagesDto::DEFAULT_LIMIT, self::MAX_LIMIT);
		$offset = $request->pagination?->getOffset() ?? 0;

		$dto = SearchMessagesDto::fromArray([
			'mailboxId' => $params->getInt('mailboxId'),
			'searchQuery' => $params->getString('searchQuery'),
			'dateFrom' => $this->convertIsoDateToInternal($params->getString('dateFrom'), 'dateFrom'),
			'dateTo' => $this->convertIsoDateToInternal($params->getString('dateTo'), 'dateTo'),
			'isSeen' => $params->getNullableBool('isSeen'),
			'hasAttachments' => $params->getNullableBool('hasAttachments'),
			'folder' => $params->getString('folder'),
			'limit' => $limit,
			'offset' => max(0, $offset),
		]);

		$provider = new MessageSearch();
		$messages = $provider->search($dto, $userId);

		$collection = new DtoCollection(MessageDto::class);

		foreach ($messages as $message)
		{
			$collection->add($this->mapMessageToDto($message));
		}

		return new ListResponse($collection);
	}

	/**
	 * Endpoint: mail.message.send
	 *
	 * Sends a new email message. Saves to the IMAP Sent folder
	 * (if the sender's mailbox exists).
	 *
	 * @restMethod mail.message.send
	 * Request params:
	 * - string   from*     Sender email address (must be available for user)
	 * - string[] to*       Array of recipient email addresses or contact names
	 * - string   subject*  Email subject line
	 * - string   body*     Email body content (plain text or basic HTML)
	 * - string[] cc        CC recipients (optional)
	 * - string[] bcc       BCC recipients (optional)
	 *
	 * @return ArrayResponse {success: bool, to: string[]}
	 */
	public function sendAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$from = $params->requireString('from');
		$subject = $params->requireString('subject');
		$body = $params->requireString('body');

		[$recipients, $cc, $bcc] = $this->resolveAddressLists(
			to: $params->requireArray('to'),
			cc: $params->getArray('cc'),
			bcc: $params->getArray('bcc'),
			userId: $userId,
		);

		try
		{
			$result = (new MessageSender())->send(
				from: $from,
				recipients: $recipients,
				subject: $subject,
				body: $body,
				userId: $userId,
				cc: $cc,
				bcc: $bcc,
			);
		}
		catch (SystemException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage(), 'MESSAGE_SEND_FAILED'),
			]);
		}

		return new ArrayResponse($result);
	}

	/**
	 * Endpoint: mail.message.reply
	 *
	 * Replies to an existing message: adds In-Reply-To header, inline attachments
	 * from the original, and the quoted original body. Saves to the IMAP Sent folder
	 * (if the sender's mailbox exists).
	 *
	 * @restMethod mail.message.reply
	 * Request params:
	 * - int      replyToMessageId*  Source message identifier
	 * - string   from*              Sender email address (must be available for user)
	 * - string[] to*                Array of recipient email addresses or contact names
	 * - string   subject*           Email subject line
	 * - string   body*              Email body content (plain text or basic HTML)
	 * - string[] cc                 CC recipients (optional)
	 * - string[] bcc                BCC recipients (optional)
	 *
	 * @return ArrayResponse {success: bool, to: string[]}
	 */
	public function replyAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->requireInt('replyToMessageId');
		$from = $params->requireString('from');
		$subject = $params->requireString('subject');
		$body = $params->requireString('body');

		[$recipients, $cc, $bcc] = $this->resolveAddressLists(
			to: $params->requireArray('to'),
			cc: $params->getArray('cc'),
			bcc: $params->getArray('bcc'),
			userId: $userId,
		);

		try
		{
			$result = (new MessageSender())->reply(
				messageId: $messageId,
				from: $from,
				recipients: $recipients,
				subject: $subject,
				body: $body,
				userId: $userId,
				cc: $cc,
				bcc: $bcc,
			);
		}
		catch (SystemException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage(), 'MESSAGE_REPLY_FAILED'),
			]);
		}

		return new ArrayResponse($result);
	}

	/**
	 * Endpoint: mail.message.forward
	 *
	 * Forwards an existing message: includes all attachments from the original
	 * and the quoted original body. Saves to the IMAP Sent folder (if the sender's
	 * mailbox exists).
	 *
	 * @restMethod mail.message.forward
	 * Request params:
	 * - int      forwardMessageId*  Source message identifier
	 * - string   from*              Sender email address (must be available for user)
	 * - string[] to*                Array of recipient email addresses or contact names
	 * - string   subject*           Email subject line
	 * - string   body*              Email body content (plain text or basic HTML)
	 * - string[] cc                 CC recipients (optional)
	 * - string[] bcc                BCC recipients (optional)
	 *
	 * @return ArrayResponse {success: bool, to: string[]}
	 */
	public function forwardAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->requireInt('forwardMessageId');
		$from = $params->requireString('from');
		$subject = $params->requireString('subject');
		$body = $params->requireString('body');

		[$recipients, $cc, $bcc] = $this->resolveAddressLists(
			to: $params->requireArray('to'),
			cc: $params->getArray('cc'),
			bcc: $params->getArray('bcc'),
			userId: $userId,
		);

		try
		{
			$result = (new MessageSender())->forward(
				messageId: $messageId,
				from: $from,
				recipients: $recipients,
				subject: $subject,
				body: $body,
				userId: $userId,
				cc: $cc,
				bcc: $bcc,
			);
		}
		catch (SystemException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage(), 'MESSAGE_FORWARD_FAILED'),
			]);
		}

		return new ArrayResponse($result);
	}

	/**
	 * @param string[] $to
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @return array{0: string[], 1: string[], 2: string[]}
	 */
	private function resolveAddressLists(array $to, array $cc, array $bcc, int $userId): array
	{
		$helper = new RecipientHelper();

		try
		{
			$recipients = $helper->resolveRecipients($to, $userId);
			$cc = !empty($cc) ? $helper->resolveRecipients($cc, $userId) : [];
			$bcc = !empty($bcc) ? $helper->resolveRecipients($bcc, $userId) : [];
		}
		catch (SystemException $e)
		{
			throw new RequestValidationException([
				new Error($e->getMessage(), 'RESOLVE_RECIPIENTS_ERROR'),
			]);
		}

		if (empty($recipients))
		{
			throw new RequestValidationException([
				new Error('No valid recipients provided.', 'NO_RECIPIENTS'),
			]);
		}

		return [$recipients, $cc, $bcc];
	}

	/**
	 * Endpoint: mail.message.createCrmActivity
	 *
	 * Creates a CRM activity from a mail message.
	 *
	 * @restMethod mail.message.createCrmActivity
	 * Request params:
	 * - int messageId*  Message identifier
	 *
	 * @return BooleanResponse {result: true} on success
	 */
	public function createCrmActivityAction(): BooleanResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->getInt('messageId', 0);
		if ($messageId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "messageId" is required.', 'MISSING_MESSAGE_ID'),
			]);
		}

		$result = MessageActions::createCrmActivity($messageId, userId: $userId);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new BooleanResponse(true);
	}

	/**
	 * Endpoint: mail.message.removeCrmActivity
	 *
	 * Removes a CRM activity binding from a mail message.
	 *
	 * @restMethod mail.message.removeCrmActivity
	 * Request params:
	 * - int messageId*  Message identifier
	 *
	 * @return DeleteResponse {result: true} on success
	 */
	public function removeCrmActivityAction(): DeleteResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->getInt('messageId', 0);
		if ($messageId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "messageId" is required.', 'MISSING_MESSAGE_ID'),
			]);
		}

		$result = MessageActions::removeCrmActivity($messageId, $userId);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		return new DeleteResponse(true);
	}

	/**
	 * Get the email thread (conversation chain) for a message.
	 *
	 * @restMethod mail.message.thread
	 * Request params:
	 * - int id*    Message identifier (any message in the thread)
	 * - int limit  Max messages to return (default 20, max 50)
	 */
	public function threadAction(GetRequest $request): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$messageId = (int)$request->id;
		$params = new RequestParams($this->getRequest()->getJsonList());
		$limit = $params->getInt('limit', 20);

		if ($limit <= 0)
		{
			$limit = 20;
		}

		if ($limit > 50)
		{
			$limit = 50;
		}

		$provider = new MessageSearch();

		try
		{
			$result = $provider->getMessageThread($messageId, $userId, $limit);
		}
		catch (SystemException)
		{
			throw new EntityNotFoundException($messageId);
		}

		return new ArrayResponse($result['messages'] ?? []);
	}

	/**
	 * Move messages to a folder, spam, or trash.
	 *
	 * @restMethod mail.message.moveToFolder
	 * Request params:
	 * - int[]  messageIds*  Array of message identifiers
	 * - string action*      Action: "move", "spam", or "delete"
	 * - string folder       Target folder name (required when action is "move")
	 */
	public function moveToFolderAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageIds = $params->requireArray('messageIds');
		$action = $params->requireString('action');
		$folder = $params->getString('folder');

		if ($action === 'move' && ($folder === null || $folder === ''))
		{
			throw new RequestValidationException([
				new Error('Parameter "folder" is required when action is "move".', 'MISSING_FOLDER'),
			]);
		}

		$result = match ($action)
		{
			'move' => MessageActions::moveToFolderByMessageIds($messageIds, $folder, $userId),
			'spam' => MessageActions::markAsSpamByMessageIds($messageIds, $userId),
			'delete' => MessageActions::deleteByMessageIds($messageIds, $userId),
			default => throw new RequestValidationException([
				new Error('Parameter "action" must be "move", "spam", or "delete".', 'INVALID_ACTION'),
			]),
		};

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		$data = $result->getData();

		return new ArrayResponse([
			'success' => true,
			'movedCount' => (int)($data['affectedCount'] ?? 0),
			'action' => $action,
		]);
	}

	/**
	 * Create a task from a mail message.
	 *
	 * @restMethod mail.message.createTask
	 * Request params:
	 * - int    messageId*     Message identifier
	 * - string title          Task title (defaults to email subject)
	 * - int    responsibleId  Responsible user ID (defaults to current user)
	 * - string description    Task description
	 */
	public function createTaskAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->getInt('messageId', 0);
		if ($messageId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "messageId" is required.', 'MISSING_MESSAGE_ID'),
			]);
		}

		$result = MessageActions::createTask(
			messageId: $messageId,
			userId: $userId,
			title: $params->getString('title'),
			responsibleId: $params->getInt('responsibleId'),
			description: $params->getString('description'),
		);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		$data = $result->getData();

		return new ArrayResponse([
			'success' => true,
			'taskId' => $data['taskId'],
			'messageId' => $messageId,
		]);
	}

	/**
	 * Create a calendar event from a mail message.
	 *
	 * @restMethod mail.message.createCalendarEvent
	 * Request params:
	 * - int    messageId*   Message identifier
	 * - string dateFrom*    Start date in 'Y-m-d H:i:s' format (e.g. '2026-04-15 10:00:00')
	 * - string dateTo*      End date in 'Y-m-d H:i:s' format (e.g. '2026-04-15 11:00:00')
	 * - string name         Event name (defaults to email subject)
	 * - string description  Event description
	 */
	public function createCalendarEventAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->getInt('messageId', 0);
		if ($messageId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "messageId" is required.', 'MISSING_MESSAGE_ID'),
			]);
		}

		$dateFrom = $params->requireString('dateFrom');
		$dateTo = $params->requireString('dateTo');

		$result = MessageActions::createCalendarEvent(
			messageId: $messageId,
			userId: $userId,
			dateFrom: $dateFrom,
			dateTo: $dateTo,
			name: $params->getString('name'),
			description: $params->getString('description'),
		);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		$data = $result->getData();

		return new ArrayResponse([
			'success' => true,
			'eventId' => $data['eventId'],
			'messageId' => $messageId,
		]);
	}

	/**
	 * Create a group chat from a mail message.
	 *
	 * @restMethod mail.message.createChat
	 * Request params:
	 * - int messageId*  Message identifier
	 */
	public function createChatAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->getInt('messageId', 0);
		if ($messageId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "messageId" is required.', 'MISSING_MESSAGE_ID'),
			]);
		}

		$result = MessageActions::createChat(
			messageId: $messageId,
			userId: $userId,
		);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		$data = $result->getData();

		return new ArrayResponse([
			'success' => true,
			'chatId' => $data['chatId'],
			'messageId' => $messageId,
			'existing' => $data['existing'] ?? false,
		]);
	}

	/**
	 * Create a feed post from a mail message.
	 *
	 * @restMethod mail.message.createFeedPost
	 * Request params:
	 * - int    messageId*  Message identifier
	 * - string title       Post title (defaults to email subject)
	 */
	public function createFeedPostAction(): ArrayResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$messageId = $params->getInt('messageId', 0);
		if ($messageId <= 0)
		{
			throw new RequestValidationException([
				new Error('Parameter "messageId" is required.', 'MISSING_MESSAGE_ID'),
			]);
		}

		$result = MessageActions::createFeedPost(
			messageId: $messageId,
			userId: $userId,
			title: $params->getString('title'),
		);

		if (!$result->isSuccess())
		{
			throw new RequestValidationException($result->getErrors());
		}

		$data = $result->getData();

		return new ArrayResponse([
			'success' => true,
			'postId' => $data['postId'],
			'messageId' => $messageId,
		]);
	}

	/**
	 * Accepts ISO 8601 (DATE_ATOM) from REST client, converts to the internal
	 * SearchMessagesDto format ('Y/m/d H:i'). Throws on malformed input instead
	 * of silently dropping the filter.
	 */
	private function convertIsoDateToInternal(?string $iso, string $fieldName): ?string
	{
		if ($iso === null || $iso === '')
		{
			return null;
		}

		$dt = \DateTime::createFromFormat(DATE_ATOM, $iso);
		if ($dt === false)
		{
			throw new RequestValidationException([
				new Error(
					"Parameter \"{$fieldName}\" must be in ISO 8601 (DATE_ATOM) format, e.g. \"2026-01-01T00:00:00+00:00\".",
					'INVALID_' . strtoupper($fieldName),
				),
			]);
		}

		return $dt->format('Y/m/d H:i');
	}

	private function mapMessageToDto(array $message): MessageDto
	{
		$dto = new MessageDto();
		$dto->id = $message['id'] ?? null;
		$dto->mailboxId = $message['mailboxId'] ?? null;
		$dto->mailboxEmail = $message['mailboxEmail'] ?? null;
		$dto->subject = $message['subject'] ?? null;
		$dto->from = $message['from'] ?? null;
		$dto->to = $message['to'] ?? null;
		$dto->cc = $message['cc'] ?? null;
		$dto->date = $message['date'] ?? null;
		$dto->isSeen = $message['isSeen'] ?? null;
		$dto->hasAttachments = $message['hasAttachments'] ?? null;
		$dto->url = $message['url'] ?? null;
		$dto->bindings = $message['bindings'] ?? null;
		$dto->body = $message['body'] ?? null;

		return $dto;
	}
}
