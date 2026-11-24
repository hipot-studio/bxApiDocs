<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\File;

use Bitrix\Im\Model\EO_FileUploadSession;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\CheckThreadPostAccess;
use Bitrix\Im\V2\Controller\Filter\DenyBatchCall;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\Upload\ChunkedUploadService;
use Bitrix\Im\V2\Entity\File\Upload\MultipartChunkFactory;
use Bitrix\Im\V2\Entity\File\Upload\UploadSessionService;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Main\Engine\Action as EngineAction;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The REST door of the chunked upload: im.v2.File.Upload.start|append|status|finish|finishToFolder|cancel.
 *
 * A file bigger than one request travels here in parts. start opens a session and answers the window a
 * part has to fit into, append takes the parts one after another, status tells where the upload stands,
 * cancel throws the whole thing away. An upload is finished by one of two actions: finish turns the
 * collected bytes into a message in the chat, finishToFolder into a file of the folder of that chat which
 * nothing has published - the caller of that half publishes it itself, or never.
 *
 * 🔴 EVERY PUBLIC ACTION OF THIS CONTROLLER IS A REST METHOD. The module declares
 * controllers.restIntegration.enabled, which exposes an action by the mere fact that it is public, so the
 * set of actions is the set below and helpers stay private.
 *
 * 🔴 THE HANDLE OF THE SESSION IS CALLED uploadId AND THE OFFSET IS CALLED offset, because the REST
 * server takes several names for itself before a method ever sees them: token sends the call into the
 * branch of a signed link ({@see \CRestServer::isTokenCheck()}), start is read as the paging offset and
 * removed from the query, state belongs to the authorization flow, _ is dropped, and auth with
 * access_token belong to OAuth. The name of the ACTION is safe - it travels in the path of the url and
 * not as a parameter - which is why there is an action called start at all.
 *
 * The bytes arrive as multipart/form-data and never as a bare body: {@see \CRestUtil::getRequestData()}
 * runs parse_str over a raw body and lets the result overwrite the parameters of the call.
 */
class Upload extends BaseController
{
	/**
	 * Actions addressed by uploadId. start is not one of them: it has no session to be addressed by.
	 *
	 * Written the way {@see self::processBeforeAction()} compares them - lowercased - and not the way they
	 * are spelled in the configuration and in the url.
	 */
	private const SESSION_ADDRESSED_ACTIONS = ['append', 'status', 'finish', 'finishtofolder', 'cancel'];

	private ?UploadSessionService $sessions = null;
	private ?ChunkedUploadService $uploads = null;

	private bool $addressedBySession = false;

	/** @var array<string, EO_FileUploadSession|null> sessions already looked up in this request */
	private array $sessionsByUploadId = [];

	public function configureActions(): array
	{
		return [
			// A REST batch carries one body for up to CRestUtil::BATCH_MAX_LENGTH sub-calls, so every
			// append inside it would be handed the very same part and write the same bytes again. start
			// is kept out of a batch for the other reason: one body would otherwise open fifty sessions,
			// and a session costs a row and up to the whole ceiling of a file in temporary bytes.
			'start' => $this->configureDoorAction(new DenyBatchCall()),
			'append' => $this->configureDoorAction(new DenyBatchCall()),
			'status' => $this->configureDoorAction(),
			'finish' => $this->configureDoorAction(),
			'finishToFolder' => $this->configureDoorAction(),
			'cancel' => $this->configureDoorAction(),
		];
	}

	/**
	 * Which action is running is known before its arguments are bound, and the wiring of the chat depends
	 * on it: see {@see self::getAutoWiredParameters()}.
	 */
	protected function processBeforeAction(EngineAction $action)
	{
		$this->addressedBySession = in_array(
			mb_strtolower($action->getName()),
			self::SESSION_ADDRESSED_ACTIONS,
			true,
		);

		return parent::processBeforeAction($action);
	}

	/**
	 * 🔴 AN ACTION ADDRESSED BY uploadId HAS EXACTLY ONE WAY OF NAMING ITS CHAT, and that is the row of the
	 * session. The module binds a chat by dialogId and by chatId as well, every rule of the three has the
	 * same priority in the queue of the binder, and a request carrying a dialogId next to an uploadId would
	 * then be bound by whichever rule came out of the queue first - leaving the right of one chat checked
	 * over the bytes of another. Dropping the other two rules for these five actions removes the choice
	 * instead of resolving it.
	 *
	 * start keeps the usual wiring of the module: it has no session yet, and the chat it opens one in is
	 * named the way every other method of im names a chat.
	 */
	public function getAutoWiredParameters()
	{
		if (!$this->addressedBySession)
		{
			return parent::getAutoWiredParameters();
		}

		// Both are built out of one row, found once and remembered on the controller.
		return [
			new ExactParameter(
				EO_FileUploadSession::class,
				'session',
				fn ($className, string $uploadId): ?EO_FileUploadSession => $this->findSession($uploadId),
			),
			new ExactParameter(
				Chat::class,
				'chat',
				fn ($className, string $uploadId): ?Chat => $this->findChatOfSession($uploadId),
			),
		];
	}

	/**
	 * Opens a session and answers the handle of it together with the window one part has to fit into.
	 *
	 * fields carries name, size and type of the whole file. All three are written down once and are what
	 * every later call of this door decides by; the parts themselves carry bytes and nothing else.
	 *
	 * $restServer is never read here: it is the argument DenyBatchCall looks for.
	 *
	 * @restMethod im.v2.File.Upload.start
	 */
	public function startAction(
		Chat $chat,
		array $fields,
		CurrentUser $currentUser,
		?\CRestServer $restServer = null,
	): ?array
	{
		return $this->answer($this->getUploads()->start(
			$chat,
			(int)$currentUser->getId(),
			(string)($fields['name'] ?? ''),
			(int)($fields['size'] ?? 0),
			(string)($fields['type'] ?? ''),
		));
	}

	/**
	 * Takes one part of the file, sent as multipart/form-data under the name chunk.
	 *
	 * $restServer is never read here: it is the argument DenyBatchCall looks for.
	 *
	 * @restMethod im.v2.File.Upload.append
	 */
	public function appendAction(
		?Chat $chat,
		?EO_FileUploadSession $session,
		int $offset,
		?\CRestServer $restServer = null,
	): ?array
	{
		$refusal = $this->findDoorRefusal($chat, $session);
		if ($refusal !== null)
		{
			return $this->refuse($refusal);
		}

		$chunkResult = MultipartChunkFactory::fromRequest($this->getRequest(), $session, $offset);
		if (!$chunkResult->isSuccess())
		{
			return $this->answer($chunkResult);
		}

		return $this->answer($this->getUploads()->append($session, $chat, $chunkResult->getData()['chunk']));
	}

	/**
	 * Tells how many bytes of the file the door already holds, so a caller that lost the answer to a part
	 * knows where to resume from without sending anything twice.
	 *
	 * @restMethod im.v2.File.Upload.status
	 */
	public function statusAction(?Chat $chat, ?EO_FileUploadSession $session): ?array
	{
		$refusal = $this->findDoorRefusal($chat, $session);
		if ($refusal !== null)
		{
			return $this->refuse($refusal);
		}

		return $this->answer($this->getUploads()->status($session, $chat));
	}

	/**
	 * Turns the collected bytes into a file of the chat and publishes the message carrying it. The answer
	 * repeats the answer of im.v2.File.upload key for key, and a repeat of the call answers the same
	 * thing again instead of publishing a second message.
	 *
	 * @restMethod im.v2.File.Upload.finish
	 */
	public function finishAction(?Chat $chat, ?EO_FileUploadSession $session, ?string $message = null): ?array
	{
		$refusal = $this->findDoorRefusal($chat, $session);
		if ($refusal !== null)
		{
			return $this->refuse($refusal);
		}

		return $this->answer($this->getUploads()->finish($session, $chat, $message));
	}

	/**
	 * Registers the collected bytes in the chat folder without publishing a message. A successful
	 * answer contains a non-null file, chatId and dialogId; its ID can be published by im.disk.file.commit.
	 * A repeat returns the same file, or FILE_UPLOAD_FILE_GONE if that file was removed.
	 *
	 * @restMethod im.v2.File.Upload.finishToFolder
	 */
	public function finishToFolderAction(?Chat $chat, ?EO_FileUploadSession $session): ?array
	{
		$refusal = $this->findDoorRefusal($chat, $session);
		if ($refusal !== null)
		{
			return $this->refuse($refusal);
		}

		return $this->answer($this->getUploads()->finishToFolder($session, $chat));
	}

	/**
	 * Throws the session away together with every byte accepted into it. A file already published stays
	 * published: taking a message back is not what this action promises.
	 *
	 * @restMethod im.v2.File.Upload.cancel
	 */
	public function cancelAction(?Chat $chat, ?EO_FileUploadSession $session): ?array
	{
		$refusal = $this->findDoorRefusal($chat, $session);
		if ($refusal !== null)
		{
			return $this->refuse($refusal);
		}

		// Nothing of the session is left to answer by, so the answer is that it is gone.
		return $this->answer($this->getUploads()->cancel($session), ['result' => true]);
	}

	/**
	 * @param ActionFilter\Base ...$additionalFilters filters this action needs beyond the ones every
	 *        action of the door has; fresh instances, because a filter keeps the action it runs for
	 *
	 * @return array{'+prefilters': ActionFilter\Base[]}
	 */
	private function configureDoorAction(ActionFilter\Base ...$additionalFilters): array
	{
		return [
			'+prefilters' => array_merge(
				[
					// The door takes a body of its own and answers in codes of its own: there is no
					// browser caller for it, and an ajax one would reach it without the scope of REST.
					new ActionFilter\Scope(ActionFilter\Scope::REST),
					// A file goes into a discussion by the very rules a message does, so it is refused
					// by the same reasons and has to name them alike - the same pair im.v2.File.upload
					// stands behind.
					new CheckThreadPostAccess(),
					new CheckActionAccess(Action::Send),
				],
				$additionalFilters,
			),
		];
	}

	/**
	 * 🔴 AN UNKNOWN HANDLE AND THE HANDLE OF ANOTHER USER ANSWER ALIKE, and a session of the caller's own
	 * that has run out is named by its own code: for the owner of it that is not a leak but the difference
	 * between "resume" and "start over".
	 *
	 * CheckActionAccess already answers CHAT_NOT_FOUND for a chat that could not be built; the same answer
	 * stands here so that the action keeps it if the prefilter is ever taken off.
	 *
	 * The two arguments are checked against each other on purpose. Both are bound by the same uploadId,
	 * but the chat of this module is also bound by dialogId and by chatId, and a request carrying one of
	 * those next to an uploadId must never end up with the rights of one chat and the bytes of another.
	 */
	private function findDoorRefusal(?Chat $chat, ?EO_FileUploadSession $session): ?Error
	{
		if ($chat === null || $session === null || (int)$chat->getId() !== (int)$session->getChatId())
		{
			return new ChatError(ChatError::NOT_FOUND);
		}

		if ($this->getSessions()->isExpired($session))
		{
			return new FileError(FileError::UPLOAD_SESSION_EXPIRED);
		}

		return null;
	}

	/**
	 * @param array|null $data what to answer with instead of the data of the result, when the result
	 *        carries none of its own
	 */
	private function answer(Result $result, ?array $data = null): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $data ?? $result->getData();
	}

	private function refuse(Error $error): ?array
	{
		$this->addError($error);

		return null;
	}

	private function findSession(string $uploadId): ?EO_FileUploadSession
	{
		if (!array_key_exists($uploadId, $this->sessionsByUploadId))
		{
			$this->sessionsByUploadId[$uploadId] = $this->getSessions()->findOwn(
				$uploadId,
				(int)$this->getCurrentUser()?->getId(),
			);
		}

		return $this->sessionsByUploadId[$uploadId];
	}

	private function findChatOfSession(string $uploadId): ?Chat
	{
		$session = $this->findSession($uploadId);
		if ($session === null)
		{
			return null;
		}

		$chat = Chat::getInstance((int)$session->getChatId());

		// A chat that went away while the upload was running is answered by the same single refusal as an
		// unknown handle, instead of by whatever a chat that does not exist refuses with.
		return $chat->isExist() ? $chat->withContextUser((int)$session->getCreatedBy()) : null;
	}

	private function getSessions(): UploadSessionService
	{
		return $this->sessions ??= new UploadSessionService();
	}

	private function getUploads(): ChunkedUploadService
	{
		return $this->uploads ??= new ChunkedUploadService();
	}
}
