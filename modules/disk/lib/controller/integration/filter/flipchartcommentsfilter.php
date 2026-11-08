<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Integration\Filter;

use Bitrix\Disk\Controller\Integration\Flipchart\Comments;
use Bitrix\Disk\Document\Flipchart\Comment\AccessContext;
use Bitrix\Disk\Document\Flipchart\Comment\ContextResolver;
use Bitrix\Disk\Document\Flipchart\Comment\RequestValidator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class FlipchartCommentsFilter extends Base
{
	public function __construct(
		private readonly string $operation,
		private readonly ContextResolver $contextResolver,
		private readonly RequestValidator $requestValidator,
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		$controller = $this->getAction()->getController();
		if (!$controller instanceof Comments)
		{
			$this->addError(self::internalError());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$request = $controller->getRequest();
		$contextResult = $this->contextResolver->resolve($this->operation, $request);
		if (!$contextResult->isSuccess())
		{
			$this->addErrors($contextResult->getErrors());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$context = $contextResult->getData()['context'] ?? null;
		if (!$context instanceof AccessContext)
		{
			$this->addError(self::internalError());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$threadId = $request->getQuery(RequestValidator::FIELD_THREAD_ID);
		$commentId = $request->getQuery(RequestValidator::FIELD_COMMENT_ID);
		$attachmentId = $request->getQuery(RequestValidator::FIELD_ATTACHMENT_ID);
		$validationResult = $this->requestValidator->validate(
			$this->operation,
			$request,
			is_string($threadId) ? $threadId : null,
			is_string($commentId) ? $commentId : null,
			is_string($attachmentId) ? $attachmentId : null,
		);
		if (!$validationResult->isSuccess())
		{
			$this->addErrors($validationResult->getErrors());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$controller->acceptResolvedRequest($context, $validationResult->getData());

		return null;
	}

	private static function internalError(): Error
	{
		return new Error('Internal server error.', 'internal_error', ['status' => 500]);
	}
}
