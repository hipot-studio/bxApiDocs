<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\File;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Meta\ActionsMetadata;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\Restrictions\EditingRestrictionResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\Public\Service\UnifiedLink\UrlGenerator;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\SystemException;

/**
 * Keeps the editing address of a document honest.
 *
 * A document the portal leaves to viewing only still opens, but it opens at the address without the
 * edit suffix: a page that shows a viewer under a url promising an editor is the same document
 * twice, and the second one lies. Which of the reasons closed the editor - the format the portal
 * restricts or the personal quota the employee has run over - the address does not tell apart: the
 * words about it belong to the page, and the route only sends the browser to the right one of the
 * two.
 *
 * Both reasons are lifted by a change of state the portal makes, and the very same url starts
 * opening the editor again, so the answer is a temporary redirect and not a permanent one.
 */
class RestrictedFormatToViewRedirect extends Base
{
	private readonly ActionsMetadata $actionsMetaData;
	private readonly EditingRestrictionResolver $restriction;
	private readonly UrlManager $urlManager;
	private ?string $urlToRedirect = null;

	public function __construct(
		private readonly Controller $controller,
	) {
		parent::__construct();

		$this->actionsMetaData = new ActionsMetadata($this->controller);
		$this->restriction = new EditingRestrictionResolver();
		$this->urlManager = new UrlManager();
	}

	/**
	 * @throws SystemException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		$action = $this->getAction();
		/** @var ?UnifiedLinkRenderer $service */
		$service = $action->getArguments()['service'] ?? null;

		if ($service === null)
		{
			return null;
		}

		if ($this->actionsMetaData->getAccessLevel($action->getName()) !== UnifiedLinkAccessLevel::Edit)
		{
			return null;
		}

		// the link may lead to a folder as well, and a folder is neither edited nor judged by a format
		$file = $service->resolveBaseObject();
		if (!$file instanceof File)
		{
			return null;
		}

		if (!$this->restriction->isRestricted($this->resolveDocumentName($service, $file), $file))
		{
			return null;
		}

		$this->urlToRedirect = $this->urlManager->getUnifiedLink($file, $this->collectViewLinkOptions());

		return new EventResult(EventResult::ERROR);
	}

	public function onAfterAction(Event $event): void
	{
		if ($this->urlToRedirect !== null)
		{
			$event->setParameter('result', new Redirect($this->urlToRedirect));
		}
	}

	/**
	 * What the address of the view page carries over from the request. The address itself is
	 * generated from the file, not rebuilt out of the address the request arrived under: the request
	 * decides nothing but the values named here, so no host, scheme or parameter of its own can
	 * travel into a redirect the portal sends the browser to.
	 *
	 * The page needs the attachment or the version that chooses what is rendered, and the marker
	 * that keeps the route from sending the browser on again. The generator drops the empty ones.
	 */
	private function collectViewLinkOptions(): array
	{
		$request = $this->controller->getRequest();

		return [
			'attachedId' => (int)$request->get(UrlGenerator::QUERY_PARAM_ATTACHED_ID),
			'versionId' => (int)$request->get(UrlGenerator::QUERY_PARAM_VERSION_ID),
			'noRedirect' => $request->get(UrlGenerator::QUERY_PARAM_NO_REDIRECT) !== null,
		];
	}

	/**
	 * The name the session seam would judge, so that the address and the page agree on the verdict:
	 * a link resolved to a version is answered by the name of that version, everything else by the
	 * name the file carries now.
	 *
	 * Both come from the renderer of the route, which has already resolved the one against the
	 * other. An id taken from the request would answer for a version of any file at all, and the
	 * page would then be judged by the name of a document it does not show.
	 */
	private function resolveDocumentName(UnifiedLinkRenderer $service, File $file): ?string
	{
		return $service->resolveVersion()?->getName() ?? $file->getName();
	}
}
