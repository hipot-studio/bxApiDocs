<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\SystemException;

class RedirectToCorrectPrefix extends Base
{
	private ?HttpResponse $redirect = null;
	public function __construct(
		private readonly Controller $controller,
	) {
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
		/** @var ?UnifiedLinkFileRenderer $service */
		$service = $action->getArguments()['service'] ?? null;

		if ($service === null)
		{
			return null;
		}

		$redirect = $this->redirectToCorrectUrl($service);

		if ($redirect)
		{
			$this->redirect = $redirect;
			return new EventResult(EventResult::ERROR);
		}
		return null;
	}

	public function onAfterAction(Event $event): void
	{
		if ($this->redirect)
		{
			$event->setParameter('result', $this->redirect);
		}
	}

	private function redirectToCorrectUrl(?UnifiedLinkFileRenderer $service): ?HttpResponse
	{
		if ($service === null)
		{
			return (new HttpResponse())
				->setStatus(404)
				->setContent(UnifiedLinkFileRenderer::renderAccessDeniedPage())
				;
		}

		$request = $this->controller->getRequest();
		$uniqueCode = $service->resolveFile()->getUniqueCode();
		$uri = explode($uniqueCode, $request->getRequestUri());
		$correctUri = explode($uniqueCode, (new UrlManager())->getUnifiedLink($service->resolveFile()));

		if ($uri[0] === $correctUri[0])
		{
			return null;
		}

		array_shift($uri);
		$new_url = $correctUri[0] . $uniqueCode . implode($uniqueCode, $uri);

		return (new Redirect($new_url));
	}

}