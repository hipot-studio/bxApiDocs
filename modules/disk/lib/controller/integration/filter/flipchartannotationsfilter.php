<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Integration\Filter;

use Bitrix\Disk\Controller\Integration\Flipchart\Annotations;
use Bitrix\Disk\Document\Flipchart\Annotation\AccessContext;
use Bitrix\Disk\Document\Flipchart\Annotation\ContextResolver;
use Bitrix\Disk\Document\Flipchart\Annotation\RequestValidator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class FlipchartAnnotationsFilter extends Base
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
		if (!$controller instanceof Annotations)
		{
			$this->addError(self::internalError());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$request = $controller->getRequest();
		$contextResult = $this->contextResolver->resolve($request);
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

		$annotationId = $request->getQuery(RequestValidator::FIELD_ANNOTATION_ID);
		$validationResult = $this->requestValidator->validate(
			$this->operation,
			$request,
			is_string($annotationId) ? $annotationId : null,
		);
		if (!$validationResult->isSuccess())
		{
			$this->addErrors($validationResult->getErrors());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$input = $validationResult->getData();
		$hasMismatchedDocument = array_key_exists(RequestValidator::FIELD_DOCUMENT_ID, $input)
			&& !$context->matchesDocument($input[RequestValidator::FIELD_DOCUMENT_ID]);
		if (!$context->isDocumentResolved() || $hasMismatchedDocument)
		{
			$this->addError(new Error(
				'Document was not found.',
				'document_not_found',
				['status' => 404],
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		$controller->acceptResolvedRequest($context, $input);

		return null;
	}

	private static function internalError(): Error
	{
		return new Error('Internal server error.', 'internal_error', ['status' => 500]);
	}
}
