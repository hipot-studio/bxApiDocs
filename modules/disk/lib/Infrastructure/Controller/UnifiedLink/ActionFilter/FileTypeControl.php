<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Meta\ActionsMetadata;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\SystemException;

class FileTypeControl extends Base
{
	private UrlManager $urlManager;
	private ActionsMetadata $actionsMetaData;
	private bool $shouldRedirectToView = false;
	private bool $fileTypeNotAllowed = false;
	private ?BaseObject $baseObject = null;

	public function __construct(
		private readonly Controller $controller,
	) {
		parent::__construct();

		$this->urlManager = new UrlManager();
		$this->actionsMetaData = new ActionsMetadata($this->controller);
	}

	/**
	 * @param Event $event
	 * @return EventResult|null
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$action = $this->getAction();
		/** @var UnifiedLinkRenderer $service */
		$service = $action->getArguments()['service'] ?? null;

		if ($service === null)
		{
			return null;
		}

		$this->baseObject = $service->resolveBaseObject();

		if ($this->actionsMetaData->shouldRedirectToView($this->action->getName(), $this->baseObject))
		{
			$this->shouldRedirectToView = true;

			return new EventResult(EventResult::ERROR);
		}

		if (
			$this->baseObject instanceof File
			&& !$this->actionsMetaData->isFileTypeAllowed($action->getName(), $this->baseObject)
		)
		{
			$this->fileTypeNotAllowed = true;

			return new EventResult(EventResult::ERROR);
		}

		return null;
	}

	public function onAfterAction(Event $event): void
	{
		if ($this->shouldRedirectToView && $this->baseObject instanceof BaseObject)
		{
			$response = new Redirect($this->urlManager->getUnifiedLink($this->baseObject));

			$event->setParameter('result', $response);

			return;
		}

		if ($this->fileTypeNotAllowed)
		{
			$response = (new HttpResponse())
				->setStatus(400)
				->setContent('Operation not supported for this file type')
			;

			$event->setParameter('result', $response);
		}
	}
}
