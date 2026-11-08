<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Integration\Flipchart;

use Bitrix\Disk\Controller\Integration\Filter\FlipchartCommentsFilter;
use Bitrix\Disk\Document\Flipchart\Comment\AccessContext;
use Bitrix\Disk\Document\Flipchart\Comment\AttachmentService;
use Bitrix\Disk\Document\Flipchart\Comment\CommentService;
use Bitrix\Disk\Document\Flipchart\Comment\ContextResolver;
use Bitrix\Disk\Document\Flipchart\Comment\RequestValidator;
use Bitrix\Disk\Document\Flipchart\Comment\Request\SetThreadFlagRequest;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Engine\Response\Json;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Response;
use Bitrix\Main\Result;

final class Comments extends Controller
{
	private const ALLOWED_METHODS = [
		'setThreadImportant' => 'PUT',
		'setThreadPinned' => 'PUT',
		'addThread' => 'GET, POST',
		'getThreadsByFilter' => 'GET, POST',
		'getThreadMeta' => 'GET, PATCH, DELETE',
		'updateThreadCoordinates' => 'GET, PATCH, DELETE',
		'deleteThread' => 'GET, PATCH, DELETE',
		'restoreThread' => 'POST',
		'resolveThread' => 'POST',
		'unresolveThread' => 'POST',
		'addComment' => 'GET, POST',
		'getThreadComments' => 'GET, POST',
		'countCommentsInThread' => 'GET',
		'getMaxCommentId' => 'GET',
		'getComment' => 'GET, PATCH, DELETE',
		'updateComment' => 'GET, PATCH, DELETE',
		'deleteComment' => 'GET, PATCH, DELETE',
		'recoverComment' => 'POST',
		'addReaction' => 'POST, DELETE',
		'removeReaction' => 'POST, DELETE',
		'markCommentsAsRead' => 'POST',
		'markThreadAsUnread' => 'POST',
		'markAllThreadsAsRead' => 'POST',
		'isFlipReadAll' => 'GET',
		'getReadStatuses' => 'POST',
		'getMentions' => 'GET',
		'uploadCommentAttachment' => 'POST',
		'getCommentAttachment' => 'GET',
	];

	private const ACTION_METHODS = [
		RequestValidator::OPERATION_SET_IMPORTANT => HttpMethod::METHOD_PUT,
		RequestValidator::OPERATION_SET_PINNED => HttpMethod::METHOD_PUT,
		RequestValidator::OPERATION_ADD_THREAD => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_GET_THREADS => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_GET_THREAD_META => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_UPDATE_THREAD_COORDINATES => HttpMethod::METHOD_PATCH,
		RequestValidator::OPERATION_DELETE_THREAD => HttpMethod::METHOD_DELETE,
		RequestValidator::OPERATION_RESTORE_THREAD => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_RESOLVE_THREAD => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_UNRESOLVE_THREAD => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_ADD_COMMENT => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_GET_THREAD_COMMENTS => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_COUNT_COMMENTS => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_GET_MAX_COMMENT_ID => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_GET_COMMENT => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_UPDATE_COMMENT => HttpMethod::METHOD_PATCH,
		RequestValidator::OPERATION_DELETE_COMMENT => HttpMethod::METHOD_DELETE,
		RequestValidator::OPERATION_RECOVER_COMMENT => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_ADD_REACTION => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_REMOVE_REACTION => HttpMethod::METHOD_DELETE,
		RequestValidator::OPERATION_MARK_READ => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_MARK_UNREAD => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_MARK_ALL_READ => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_IS_READ_ALL => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_GET_READ_STATUSES => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_GET_MENTIONS => HttpMethod::METHOD_GET,
		RequestValidator::OPERATION_UPLOAD_ATTACHMENT => HttpMethod::METHOD_POST,
		RequestValidator::OPERATION_GET_ATTACHMENT => HttpMethod::METHOD_GET,
	];

	private ?AccessContext $accessContext = null;

	/** @var array<string, mixed>|null */
	private ?array $input = null;

	private bool $hasUnexpectedFailure = false;

	public function configureActions(): array
	{
		$contextResolver = new ContextResolver();
		$requestValidator = new RequestValidator();

		$configuration = [];
		foreach (self::ACTION_METHODS as $operation => $method)
		{
			if ($operation === RequestValidator::OPERATION_GET_ATTACHMENT)
			{
				continue;
			}

			$configuration[$operation] = [
				'-prefilters' => [
					Authentication::class,
					Csrf::class,
					HttpMethod::class,
				],
				'+prefilters' => [
					new FlipchartCommentsFilter($operation, $contextResolver, $requestValidator),
					new HttpMethod([$method]),
				],
			];
		}
		$configuration[RequestValidator::OPERATION_GET_ATTACHMENT] = [
			'-prefilters' => [
				Authentication::class,
				Csrf::class,
				HttpMethod::class,
			],
			'+prefilters' => [
				new HttpMethod([HttpMethod::METHOD_GET]),
			],
		];

		return $configuration;
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new Parameter(
				CommentService::class,
				static fn(string $className): CommentService => new CommentService(),
			),
			new Parameter(
				AttachmentService::class,
				static fn(string $className): AttachmentService => new AttachmentService(),
			),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function acceptResolvedRequest(AccessContext $context, array $input): void
	{
		$this->accessContext = $context;
		$this->input = $input;
	}

	public function setThreadImportantAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_SET_IMPORTANT);
	}

	public function setThreadPinnedAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_SET_PINNED);
	}

	public function addThreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_ADD_THREAD);
	}

	public function getThreadsByFilterAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_THREADS);
	}

	public function getThreadMetaAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_THREAD_META);
	}

	public function updateThreadCoordinatesAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_UPDATE_THREAD_COORDINATES);
	}

	public function deleteThreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_DELETE_THREAD);
	}

	public function restoreThreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_RESTORE_THREAD);
	}

	public function resolveThreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_RESOLVE_THREAD);
	}

	public function unresolveThreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_UNRESOLVE_THREAD);
	}

	public function addCommentAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_ADD_COMMENT);
	}

	public function getThreadCommentsAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_THREAD_COMMENTS);
	}

	public function countCommentsInThreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_COUNT_COMMENTS);
	}

	public function getMaxCommentIdAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_MAX_COMMENT_ID);
	}

	public function getCommentAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_COMMENT);
	}

	public function updateCommentAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_UPDATE_COMMENT);
	}

	public function deleteCommentAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_DELETE_COMMENT);
	}

	public function recoverCommentAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_RECOVER_COMMENT);
	}

	public function addReactionAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_ADD_REACTION);
	}

	public function removeReactionAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_REMOVE_REACTION);
	}

	public function markCommentsAsReadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_MARK_READ);
	}

	public function markThreadAsUnreadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_MARK_UNREAD);
	}

	public function markAllThreadsAsReadAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_MARK_ALL_READ);
	}

	public function isFlipReadAllAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_IS_READ_ALL);
	}

	public function getReadStatusesAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_READ_STATUSES);
	}

	public function getMentionsAction(CommentService $service): Result
	{
		return $this->execute($service, RequestValidator::OPERATION_GET_MENTIONS);
	}

	/**
	 * The one operation served by another service: the upload carries a file and no thread.
	 */
	public function uploadCommentAttachmentAction(AttachmentService $service): Result
	{
		if ($this->accessContext === null || $this->input === null)
		{
			$result = new Result();
			$result->addError(self::internalError());

			return $result;
		}

		return $service->upload($this->accessContext, $this->input);
	}

	public function getCommentAttachmentAction(AttachmentService $service, string $attachmentId): Result|BFile
	{
		$result = $service->getFile($attachmentId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		Context::getCurrent()->getResponse()->addHeader('X-Content-Type-Options', 'nosniff');

		return BFile::createByFileId($result->getData()['fileId'])
			->showInline(true)
			->setCacheTime(0)
		;
	}

	protected function processAfterAction(Action $action, $result): HttpResponse
	{
		if ($result instanceof HttpResponse)
		{
			return $result;
		}

		if ($result instanceof Result)
		{
			return $this->createResultResponse($result, $action);
		}

		$errors = $this->getErrors();

		return $this->createErrorResponse($errors[0] ?? self::internalError(), $action);
	}

	protected function runProcessingThrowable(\Throwable $throwable)
	{
		$this->hasUnexpectedFailure = true;
		$this->addError(self::internalError());
	}

	public function finalizeResponse(Response $response): void
	{
		if (!$this->hasUnexpectedFailure || !$response instanceof HttpResponse)
		{
			return;
		}

		$errorResponse = $this->createErrorResponse(self::internalError());
		$response->setContent($errorResponse->getContent());
		$response->setStatus($errorResponse->getStatus());
	}

	private function execute(CommentService $service, string $operation): Result
	{
		if ($this->accessContext === null || $this->input === null)
		{
			$result = new Result();
			$result->addError(self::internalError());

			return $result;
		}

		if (in_array($operation, [RequestValidator::OPERATION_SET_IMPORTANT, RequestValidator::OPERATION_SET_PINNED], true))
		{
			$request = new SetThreadFlagRequest($this->input['threadId'], $this->input['userId'], $this->input['value']);

			return $operation === RequestValidator::OPERATION_SET_IMPORTANT
				? $service->setThreadImportant($this->accessContext, $request)
				: $service->setThreadPinned($this->accessContext, $request)
			;
		}

		return match ($operation)
		{
			RequestValidator::OPERATION_ADD_THREAD => $service->addThread($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_THREADS => $service->getThreadsByFilter($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_THREAD_META => $service->getThreadMeta($this->accessContext, $this->input),
			RequestValidator::OPERATION_UPDATE_THREAD_COORDINATES => $service->updateThreadCoordinates($this->accessContext, $this->input),
			RequestValidator::OPERATION_DELETE_THREAD => $service->deleteThread($this->accessContext, $this->input),
			RequestValidator::OPERATION_RESTORE_THREAD => $service->restoreThread($this->accessContext, $this->input),
			RequestValidator::OPERATION_RESOLVE_THREAD => $service->resolveThread($this->accessContext, $this->input),
			RequestValidator::OPERATION_UNRESOLVE_THREAD => $service->unresolveThread($this->accessContext, $this->input),
			RequestValidator::OPERATION_ADD_COMMENT => $service->addComment($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_THREAD_COMMENTS => $service->getThreadComments($this->accessContext, $this->input),
			RequestValidator::OPERATION_COUNT_COMMENTS => $service->countComments($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_MAX_COMMENT_ID => $service->getMaxCommentId($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_COMMENT => $service->getComment($this->accessContext, $this->input),
			RequestValidator::OPERATION_UPDATE_COMMENT => $service->updateComment($this->accessContext, $this->input),
			RequestValidator::OPERATION_DELETE_COMMENT => $service->deleteComment($this->accessContext, $this->input),
			RequestValidator::OPERATION_RECOVER_COMMENT => $service->recoverComment($this->accessContext, $this->input),
			RequestValidator::OPERATION_ADD_REACTION => $service->addReaction($this->accessContext, $this->input),
			RequestValidator::OPERATION_REMOVE_REACTION => $service->removeReaction($this->accessContext, $this->input),
			RequestValidator::OPERATION_MARK_READ => $service->markCommentsAsRead($this->accessContext, $this->input),
			RequestValidator::OPERATION_MARK_UNREAD => $service->markThreadAsUnread($this->accessContext, $this->input),
			RequestValidator::OPERATION_MARK_ALL_READ => $service->markAllThreadsAsRead($this->accessContext, $this->input),
			RequestValidator::OPERATION_IS_READ_ALL => $service->isFlipReadAll($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_READ_STATUSES => $service->getReadStatuses($this->accessContext, $this->input),
			RequestValidator::OPERATION_GET_MENTIONS => $service->getMentions($this->accessContext, $this->input),
		};
	}

	/**
	 * A success without a body is answered with its status alone: `204` carries neither JSON nor a
	 * content type.
	 */
	private function createResultResponse(Result $result, ?Action $action = null): HttpResponse
	{
		if (!$result->isSuccess())
		{
			return $this->createErrorResponse($result->getError() ?? self::internalError(), $action);
		}

		$data = $result->getData();
		$status = $data['status'] ?? null;
		$body = $data['response'] ?? null;
		if ($status === CommentService::STATUS_NO_CONTENT && $body === null)
		{
			return (new HttpResponse())->setStatus($status);
		}
		if (!is_int($status) || !is_array($body))
		{
			return $this->createErrorResponse(self::internalError(), $action);
		}

		$response = new Json($body);
		$response->setStatus($status);

		return $response;
	}

	private function createErrorResponse(Error $error, ?Action $action = null): Json
	{
		$customData = $error->getCustomData();
		$status = is_array($customData) ? ($customData['status'] ?? null) : null;
		$code = $error->getCode();
		$message = $error->getMessage();

		if ($code === HttpMethod::ERROR_INVALID_HTTP_METHOD)
		{
			$status = 405;
			$code = 'method_not_allowed';
			$message = 'Method is not allowed.';
		}
		elseif (!is_int($status) || !is_string($code) || $code === '')
		{
			$status = 500;
			$code = 'internal_error';
			$message = 'Internal server error.';
		}

		$response = new Json([
			'code' => $code,
			'message' => $message,
		]);
		$response->setStatus($status);

		$actionName = $action?->getName();
		if ($status === 405 && isset(self::ALLOWED_METHODS[$actionName]))
		{
			$response->addHeader('Allow', self::ALLOWED_METHODS[$actionName]);
		}

		return $response;
	}

	private static function internalError(): Error
	{
		return new Error('Internal server error.', 'internal_error', ['status' => 500]);
	}
}
