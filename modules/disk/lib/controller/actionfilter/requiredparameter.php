<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\ActionFilter;

use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\SystemException;

class RequiredParameter extends Base
{
	private bool $notFound = false;

	public function __construct(
		private readonly string $argumentName,
		private readonly bool   $return404page = true,
	)
	{
		parent::__construct();
	}

	/**
	 * @param Event $event
	 * @return EventResult|null
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$action = $this->getAction();
		$arguments = $action->getArguments();

		if (!isset($arguments[$this->argumentName]) || $arguments[$this->argumentName] === null)
		{
			$this->notFound = true;

			$this->addError(new Error('Required parameter "' . $this->argumentName . '" is missing.'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	public function onAfterAction(Event $event): void
	{
		if ($this->notFound)
		{
			Context::getCurrent()?->getResponse()?->setStatus(404);
		}

		if ($this->notFound && $this->return404page)
		{
			$response = new HttpResponse();
			$response->setContent(UnifiedLinkFileRenderer::renderAccessDeniedPage());
			$response->setStatus(404);

			$event->setParameter('result', $response);
		}
	}
}
