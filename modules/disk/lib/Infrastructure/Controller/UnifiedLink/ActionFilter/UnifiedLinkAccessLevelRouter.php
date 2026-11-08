<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Meta\ActionsMetadata;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\Public\Service\UnifiedLink\UrlGenerator;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Uri;

/**
 * Action filter to check access to objects based on the action being executed.
 * Redirect to a specific URL if the access level of the object does not match the action's access level.
 */
class UnifiedLinkAccessLevelRouter extends Base
{
	private bool $accessDenied = false;
	private bool $needRedirect = false;
	private string $urlToRedirect = '';
	private ?BaseObject $baseObject = null;
	private readonly ActionsMetadata $actionsMetaData;

	public function __construct(
		private readonly Controller $controller,
	) {
		parent::__construct();

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

		$baseObject = $service->resolveBaseObject();
		$this->baseObject = $baseObject;

		$accessLevel = $service->getAccessLevel();
		$shouldRenderExternal = $service->shouldRenderExternal();

		if ($accessLevel === UnifiedLinkAccessLevel::Denied && !$shouldRenderExternal)
		{
			return $this->setAccessDeniedResult();
		}

		if ($baseObject instanceof Folder && $action->getName() !== 'view')
		{
			$redirectToView = (new UrlManager())->getUnifiedLink($baseObject);

			return $this->setRedirectResult($redirectToView);
		}

		$methodAccessLevel = $this->actionsMetaData->getAccessLevel($action->getName());

		if ($methodAccessLevel !== null && $methodAccessLevel !== $accessLevel)
		{
			$urlToRedirect = $this->actionsMetaData->getUrl($accessLevel, $baseObject);

			if (isset($urlToRedirect) && $this->shouldRedirect($accessLevel, $baseObject))
			{
				$currentUri = new Uri($this->controller->getRequest()->getRequestUri());
				$uriToRedirect = (string)$currentUri->withPath($urlToRedirect);

				return $this->setRedirectResult($uriToRedirect);
			}

			if ($methodAccessLevel->value > $accessLevel->value && !$shouldRenderExternal)
			{
				return $this->setAccessDeniedResult();
			}
		}

		return null;
	}

	/**
	 * @param Event $event
	 * @return void
	 * @throws ArgumentNullException
	 * @throws ArgumentTypeException
	 */
	public function onAfterAction(Event $event): void
	{
		if ($this->accessDenied)
		{
			$response = $this->createResponse(403);
			$response->setContent(UnifiedLinkRenderer::renderAccessDeniedPageForBaseObject($this->baseObject));

			$event->setParameter('result', $response);
		}

		if ($this->needRedirect && $this->urlToRedirect !== '')
		{
			$response = $this->createResponse(302);
			$response->addHeader('Location', $this->urlToRedirect);

			$event->setParameter('result', $response);
		}
	}

	private function createResponse(int $status): HttpResponse
	{
		return (new HttpResponse())
			->setStatus($status)
		;
	}

	private function shouldRedirect(UnifiedLinkAccessLevel $accessLevel, BaseObject $baseObject): bool
	{
		$request = $this->controller->getRequest();

		if ($request->get(UrlGenerator::QUERY_PARAM_NO_REDIRECT) !== null)
		{
			return false;
		}

		if (!$baseObject instanceof File)
		{
			return false;
		}

		// some files should always open without /edit in url
		if (
			$accessLevel === UnifiedLinkAccessLevel::Edit
			&& in_array(strtolower($baseObject->getExtension()), UrlGenerator::EXTENSIONS_TO_OPEN_WITHOUT_EDIT_SUFFIX)
		)
		{
			return false;
		}

		// a document needs no redirect to editing: the mode it opens in is chosen by the renderer
		$isDocument = (int)$baseObject->getTypeFile() === TypeFile::DOCUMENT;
		if ($isDocument && $accessLevel === UnifiedLinkAccessLevel::Edit)
		{
			return false;
		}

		$actionName = $this->actionsMetaData->getActionName($accessLevel);

		return $this->actionsMetaData->isFileTypeAllowed($actionName, $baseObject);
	}

	private function setAccessDeniedResult(): EventResult
	{
		$this->accessDenied = true;

		return new EventResult(EventResult::ERROR);
	}

	private function setRedirectResult(string $urlToRedirect): EventResult
	{
		$this->needRedirect = true;
		$this->urlToRedirect = $urlToRedirect;

		return new EventResult(EventResult::ERROR);
	}
}
