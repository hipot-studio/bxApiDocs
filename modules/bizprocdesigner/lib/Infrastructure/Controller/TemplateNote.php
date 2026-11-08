<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\AnchorType;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\CompleteNoteRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\Coordinates;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\CreateNoteRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\DeleteMessageRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\GetNoteThreadRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\GetNotesRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\NoteAnchor;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\ReplyToNoteRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\UpdateMessageRequest;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\CreateNoteResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\DeleteMessageResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\GetNoteThreadResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\GetNotesResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\ReplyToNoteResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\UpdateMessageResponse;
use Bitrix\Bizproc\Api\Service\TemplateNoteService;
use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable;
use Bitrix\Bizproc\Workflow\Template\Note\MentionNames;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\TemplateNoteAction;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\TemplateNoteWatch;
use Bitrix\Intranet;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * The discussions pinned to the scheme of a workflow template. Every action is a thin wrapper: it builds
 * the request of the service, carries the coded refusals of the service to the client as they are and
 * shapes a success into the structures of the contract (DTO-01, DTO-02).
 *
 * The identifier of a template is accepted only where the operation addresses the whole template; a note
 * or a message carries its template with it. A template not saved yet has the identifier of zero and is
 * refused by the service as ACCESS_DENIED - the notes of a scheme that does not exist have nowhere
 * to live (Q-2).
 *
 * Every successful operation is announced to the other editors of the same scheme through the live
 * channel of the template (EVENT-01, {@see TemplateNoteWatch}), and the reading of the notes is where a
 * person is subscribed to it. A refused operation announces nothing.
 */
class TemplateNote extends JsonController
{
	/**
	 * The width of the anchor columns of the schema (SQL-01): a longer address would be cut on the write,
	 * and the anchor of a note is never rewritten.
	 */
	private const ANCHOR_PART_LENGTH = 128;

	/**
	 * The canvas of a scheme is measured in thousands of pixels, so the limit is orders wider than any
	 * real one and far below the int of the columns of a point (SQL-01).
	 */
	private const COORDINATE_LIMIT = 1000000;

	private ?TemplateNoteService $noteService = null;
	private TemplateNoteWatch $watch;

	public function __construct(?Request $request = null, ?TemplateNoteWatch $watch = null)
	{
		parent::__construct($request);

		$this->watch = $watch ?? new TemplateNoteWatch();
	}

	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	/**
	 * The discussions are a surface of the employees of the portal: an external or a mail user has no
	 * place in them. The filter lives in a module that may be absent from the delivery.
	 */
	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		if (Loader::includeModule('intranet'))
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	/**
	 * Every action, the reading ones included, is narrowed to POST: the transport of the editor sends
	 * them all that way, while the default filters allow GET as well.
	 */
	public function configureActions(): array
	{
		$postOnly = [
			'+prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])],
		];

		return [
			'list' => $postOnly,
			'create' => $postOnly,
			'reply' => $postOnly,
			'updateMessage' => $postOnly,
			'deleteMessage' => $postOnly,
			'complete' => $postOnly,
			'thread' => $postOnly,
		];
	}

	/**
	 * API-01. The limits travel with the list so that the editor can show the length of a message against
	 * its limit without a call of its own.
	 */
	public function listAction(int $templateId): ?array
	{
		return $this->execute(
			fn(): GetNotesResponse => $this->noteService()->getNotes(
				new GetNotesRequest($templateId, $this->getUserId()),
			),
			function (GetNotesResponse $response): array
			{
				$messageCounts = $response->getMessageCounts();

				return [
					'notes' => array_map(
						fn(EO_TemplateNote $note): array => $this->convertNote(
							$note,
							$messageCounts[$note->getId()] ?? 0,
						),
						$response->getNotes(),
					),
					'limits' => $response->getLimits(),
				];
			},
			live: fn() => $this->watch->subscribe($templateId, $this->getUserId()),
		);
	}

	/**
	 * API-02. A note never exists without its first message, so both travel back.
	 */
	public function createAction(
		int $templateId,
		array $anchor,
		array $offset,
		array $position,
		string $message,
	): ?array
	{
		// the gate of the feature stands before the payload is even read, so that a disabled feature
		// answers the same way to every request
		if ($this->refuseDisabledFeature())
		{
			return null;
		}

		$noteAnchor = self::readAnchor($anchor);
		if ($noteAnchor === null)
		{
			$this->addError(ErrorMessage::INVALID_PARAM_ARG->getCodedError([
				'#PARAM#' => 'anchor',
				'#VALUE#' => is_string($anchor['type'] ?? null) ? $anchor['type'] : '',
			]));

			return null;
		}

		$noteOffset = $this->readCoordinates($offset, 'offset');
		if ($noteOffset === null)
		{
			return null;
		}

		$notePosition = $this->readCoordinates($position, 'position');
		if ($notePosition === null)
		{
			return null;
		}

		return $this->execute(
			fn(): CreateNoteResponse => $this->noteService()->createNote(
				new CreateNoteRequest(
					$templateId,
					$this->getUserId(),
					$noteAnchor,
					$noteOffset,
					$notePosition,
					$message,
				),
			),
			fn(CreateNoteResponse $response): array => array_merge(
				[
					'note' => $this->convertNote($response->getNote(), 1),
					'message' => $this->convertSingleMessage($response->getMessage()),
				],
				self::convertNotifyOutcome($response),
			),
			live: fn(CreateNoteResponse $response) => $this->watch->notify(
				$templateId,
				(int)$response->getNote()?->getId(),
				TemplateNoteAction::NoteCreated,
			),
		);
	}

	/**
	 * API-03.
	 */
	public function replyAction(int $noteId, string $message): ?array
	{
		if ($this->refuseDisabledFeature())
		{
			return null;
		}

		$templateId = self::templateOfNote($noteId);

		return $this->execute(
			fn(): ReplyToNoteResponse => $this->noteService()->replyToNote(
				new ReplyToNoteRequest($noteId, $this->getUserId(), $message),
			),
			fn(ReplyToNoteResponse $response): array => $this->convertWrittenMessage($response),
			live: fn() => $this->watch->notify($templateId, $noteId, TemplateNoteAction::MessageCreated),
		);
	}

	/**
	 * API-04. A refusal by the revision carries the message as it stands now, so that the editor shows
	 * what it has missed and offers to repeat the edit instead of reading the conversation again.
	 */
	public function updateMessageAction(int $messageId, string $message, int $revision): ?array
	{
		if ($this->refuseDisabledFeature())
		{
			return null;
		}

		[$templateId, $noteId] = self::noteOfMessage($messageId);

		return $this->execute(
			fn(): UpdateMessageResponse => $this->noteService()->updateMessage(
				new UpdateMessageRequest($messageId, $this->getUserId(), $message, $revision),
			),
			fn(UpdateMessageResponse $response): array => $this->convertWrittenMessage($response),
			function (UpdateMessageResponse $response): ?array
			{
				$actual = $response->getActualMessage();

				return $actual === null ? null : ['actual' => $this->convertSingleMessage($actual)];
			},
			live: fn() => $this->watch->notify($templateId, $noteId, TemplateNoteAction::MessageUpdated),
		);
	}

	/**
	 * API-05. The note is gone together with its last message, and the editor removes its label by that.
	 */
	public function deleteMessageAction(int $messageId): ?array
	{
		if ($this->refuseDisabledFeature())
		{
			return null;
		}

		[$templateId, $noteId] = self::noteOfMessage($messageId);

		return $this->execute(
			fn(): DeleteMessageResponse => $this->noteService()->deleteMessage(
				new DeleteMessageRequest($messageId, $this->getUserId()),
			),
			static fn(DeleteMessageResponse $response): array => ['noteRemoved' => $response->isNoteRemoved()],
			live: fn(DeleteMessageResponse $response) => $this->watch->notify(
				$templateId,
				$noteId,
				// the note itself is gone only with its last message; the neighbours of a deleted message
				// keep their label and only their conversation has changed
				$response->isNoteRemoved() ? TemplateNoteAction::NoteRemoved : TemplateNoteAction::MessageRemoved,
			),
		);
	}

	/**
	 * API-06. Completing a note removes it with everything written in it, so a success is the whole answer.
	 */
	public function completeAction(int $noteId): ?array
	{
		if ($this->refuseDisabledFeature())
		{
			return null;
		}

		$templateId = self::templateOfNote($noteId);

		return $this->execute(
			fn(): Result => $this->noteService()->completeNote(
				new CompleteNoteRequest($noteId, $this->getUserId()),
			),
			static fn(): array => ['completed' => true],
			live: fn() => $this->watch->notify($templateId, $noteId, TemplateNoteAction::NoteRemoved),
		);
	}

	/**
	 * API-07. The conversation comes apart from the list: opening a scheme with two hundred discussions
	 * must not pull them all along.
	 */
	public function threadAction(int $noteId): ?array
	{
		return $this->execute(
			fn(): GetNoteThreadResponse => $this->noteService()->getNoteThread(
				new GetNoteThreadRequest($noteId, $this->getUserId()),
			),
			function (GetNoteThreadResponse $response): array
			{
				$messages = $response->getMessages();
				$names = MentionNames::forTexts(
					array_map(
						static fn(EO_TemplateNoteMessage $message): string => $message->getMessage(),
						$messages,
					),
				);

				return [
					'note' => $this->convertNote($response->getNote(), count($messages)),
					'messages' => array_map(
						fn(EO_TemplateNoteMessage $message): array => $this->convertMessage($message, $names),
						$messages,
					),
				];
			},
		);
	}

	/**
	 * The common body of every action: the gate of the feature, then the operation, and the coded refusals
	 * of the service travel to the client untouched. Only the shape of the answer is left to the action.
	 *
	 * @param callable(): Result $operation
	 * @param callable(Result): array $present the body of a success
	 * @param callable(Result): ?array|null $refuse what travels in the body of a refusal beside the errors
	 * @param callable(Result): void|null $live the live channel of the template: the subscription of a
	 *        reading and the signal of a write, served only after the operation has succeeded
	 */
	private function execute(
		callable $operation,
		callable $present,
		?callable $refuse = null,
		?callable $live = null,
	): ?array
	{
		if ($this->refuseDisabledFeature())
		{
			return null;
		}

		$response = $operation();
		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return $refuse === null ? null : $refuse($response);
		}

		if ($live !== null)
		{
			$live($response);
		}

		return $present($response);
	}

	/**
	 * @return bool whether the refusal has been answered, and the action is over with it
	 */
	private function refuseDisabledFeature(): bool
	{
		if (Feature::instance()->areTemplateNotesAvailable())
		{
			return false;
		}

		$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

		return true;
	}

	/**
	 * DTO-01. The template of the note is not repeated: the client works inside the one it has open, and
	 * the signal of a live update carries the template of its own.
	 */
	private function convertNote(EO_TemplateNote $note, int $messageCount): array
	{
		return [
			'id' => $note->getId(),
			'anchor' => self::convertAnchor($note),
			'offset' => [
				'x' => $note->getOffsetX(),
				'y' => $note->getOffsetY(),
			],
			'position' => [
				'x' => $note->getPositionX(),
				'y' => $note->getPositionY(),
			],
			'authorId' => $note->getAuthorId(),
			'createdAt' => self::formatDate($note->getCreated()),
			'messageCount' => $messageCount,
			'canComplete' => $this->canComplete($note),
		];
	}

	/**
	 * The kind of the anchor decides which of its parts exist at all: publishing the empty ones would let
	 * the client read the address of a node from a note pinned to a link.
	 */
	private static function convertAnchor(EO_TemplateNote $note): array
	{
		$type = AnchorType::fromStorage($note->getAnchorType()) ?? AnchorType::Free;
		$anchor = ['type' => $type->value];

		if ($type === AnchorType::Node)
		{
			$anchor['nodeId'] = $note->getAnchorNodeId();
		}

		if ($type === AnchorType::Connection)
		{
			$anchor['sourceBlockId'] = $note->getAnchorSourceBlockId();
			$anchor['sourcePortId'] = $note->getAnchorSourcePortId();
			$anchor['targetBlockId'] = $note->getAnchorTargetBlockId();
			$anchor['targetPortId'] = $note->getAnchorTargetPortId();
		}

		return $anchor;
	}

	/**
	 * DTO-02. The text travels in the markup it is stored in (DTO-03): the editor of the client renders
	 * it, and assembling ready HTML here would take that decision away from it. The one thing rewritten on
	 * the way out is the name inside a mention tag - it is the actual name of the user and not the one the
	 * author typed with (DTO-03, {@see MentionNames}).
	 *
	 * The names come in resolved for the whole answer at once: a batch built per message would cost a query
	 * per message on a conversation.
	 */
	private function convertMessage(EO_TemplateNoteMessage $message, MentionNames $names): array
	{
		$modified = $message->getModified();

		return [
			'id' => $message->getId(),
			'noteId' => $message->getNoteId(),
			'authorId' => $message->getAuthorId(),
			'text' => $names->apply($message->getMessage()),
			'revision' => $message->getRevision(),
			'createdAt' => self::formatDate($message->getCreated()),
			'modifiedAt' => $modified === null ? null : self::formatDate($modified),
			'canEdit' => $message->getAuthorId() === $this->getUserId(),
		];
	}

	/**
	 * A message that travels alone: the answer of a write carries one, and the resolution of the names of
	 * its mentions is a batch of one text ({@see MentionNames}).
	 */
	private function convertSingleMessage(EO_TemplateNoteMessage $message): array
	{
		return $this->convertMessage($message, MentionNames::forText($message->getMessage()));
	}

	/**
	 * A written message answers the same way whether it is new or rewritten.
	 */
	private function convertWrittenMessage(ReplyToNoteResponse|UpdateMessageResponse $response): array
	{
		return array_merge(
			['message' => $this->convertSingleMessage($response->getMessage())],
			self::convertNotifyOutcome($response),
		);
	}

	/**
	 * The outcome of notifying the mentioned users travels with every written message: the message is
	 * saved either way, and the author is told who was left out.
	 */
	private static function convertNotifyOutcome(
		CreateNoteResponse|ReplyToNoteResponse|UpdateMessageResponse $response,
	): array
	{
		return [
			'notified' => $response->getNotified(),
			'unavailableMentions' => $response->getUnavailableMentions(),
			'notifyFailed' => $response->isNotifyFailed(),
		];
	}

	/**
	 * The author closes his own question and the administrator of the portal closes a discussion nobody
	 * comes back to. The right to edit the template is already spent by the very reading of the notes, so
	 * only the identity is compared here - and by the same notion of an administrator the service uses,
	 * otherwise the interface would offer a button the server refuses.
	 */
	private function canComplete(EO_TemplateNote $note): bool
	{
		$userId = $this->getUserId();

		return $note->getAuthorId() === $userId || AccessController::getInstance($userId)->isAdmin();
	}

	/**
	 * The address of the note is built by the named constructors of the domain, so an anchor of one kind
	 * cannot carry the address of another.
	 *
	 * @return NoteAnchor|null null when the payload fits none of the three kinds
	 */
	private static function readAnchor(array $anchor): ?NoteAnchor
	{
		$type = AnchorType::tryFrom(is_string($anchor['type'] ?? null) ? $anchor['type'] : '');

		if ($type === AnchorType::Free)
		{
			return NoteAnchor::free();
		}

		if ($type === AnchorType::Node)
		{
			$nodeId = self::readAnchorPart($anchor, 'nodeId');

			return $nodeId === null ? null : NoteAnchor::node($nodeId);
		}

		if ($type === AnchorType::Connection)
		{
			$ends = [];
			foreach (['sourceBlockId', 'sourcePortId', 'targetBlockId', 'targetPortId'] as $part)
			{
				$end = self::readAnchorPart($anchor, $part);
				if ($end === null)
				{
					return null;
				}

				$ends[] = $end;
			}

			return NoteAnchor::connection(...$ends);
		}

		return null;
	}

	private static function readAnchorPart(array $anchor, string $part): ?string
	{
		$value = $anchor[$part] ?? null;
		if (!is_string($value) || $value === '' || mb_strlen($value) > self::ANCHOR_PART_LENGTH)
		{
			return null;
		}

		return $value;
	}

	/**
	 * The address of a live signal is read before the operation runs: completing a note and deleting the
	 * last message of one take away the very row the address would be read from afterwards. The gate of the
	 * feature stands before this reading, so a disabled feature reaches the storage not at all. Nothing of
	 * it reaches the client - the signal itself is sent only after the operation has succeeded, and the
	 * operation is the one that checks the right to the template.
	 *
	 * @return int the template of the note, or zero when the note is not there
	 */
	private static function templateOfNote(int $noteId): int
	{
		if ($noteId <= 0)
		{
			return 0;
		}

		$note = TemplateNoteTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('ID', $noteId)
			->fetch()
		;

		return (int)($note['TEMPLATE_ID'] ?? 0);
	}

	/**
	 * @return array{int, int} the template and the note of the message, both zero when it is not there
	 */
	private static function noteOfMessage(int $messageId): array
	{
		if ($messageId <= 0)
		{
			return [0, 0];
		}

		$message = TemplateNoteMessageTable::query()
			->setSelect(['NOTE_ID', 'TEMPLATE_ID' => 'NOTE.TEMPLATE_ID'])
			->where('ID', $messageId)
			->fetch()
		;

		return [(int)($message['TEMPLATE_ID'] ?? 0), (int)($message['NOTE_ID'] ?? 0)];
	}

	/**
	 * The canvas is wide but not endless: the bound keeps a point away from the int of its column (SQL-01),
	 * where the write would either fail or be cut.
	 *
	 * @return Coordinates|null null when the point is out of the canvas, the refusal already answered
	 */
	private function readCoordinates(array $point, string $param): ?Coordinates
	{
		$x = (int)($point['x'] ?? 0);
		$y = (int)($point['y'] ?? 0);

		if (abs($x) > self::COORDINATE_LIMIT || abs($y) > self::COORDINATE_LIMIT)
		{
			$this->addError(ErrorMessage::INVALID_PARAM_ARG->getCodedError([
				'#PARAM#' => $param,
				'#VALUE#' => $x . ',' . $y,
			]));

			return null;
		}

		return new Coordinates($x, $y);
	}

	private static function formatDate(DateTime $date): string
	{
		return $date->format(\DateTimeInterface::ATOM);
	}

	private function noteService(): TemplateNoteService
	{
		return $this->noteService ??= new TemplateNoteService();
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
