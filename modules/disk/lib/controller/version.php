<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Infrastructure\Controller\HtmlViewerRefusalResponse;
use Bitrix\Disk\Internal\Service\HtmlViewerService;
use Bitrix\Disk\Internal\Service\MarkdownRenderService;
use Bitrix\Disk\Internal\Service\TiffPreviewService;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\HttpResponse;

final class Version extends Engine\Controller
{
	use HtmlViewerRefusalResponse;

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();

		$configureActions['download'] =
		$configureActions['showMarkdown'] = [
			'-prefilters' => [
				ActionFilter\Csrf::class,
				ActionFilter\Authentication::class,
			],
			'+prefilters' => [
				new ActionFilter\Authentication(true),
				new ActionFilter\CloseSession(),
			]
		];
		$configureActions['showHtml'] = $configureActions['showMarkdown'];
		$configureActions['showHtml']['+prefilters'] = array_values(array_filter(
			$configureActions['showHtml']['+prefilters'],
			static fn(object $filter): bool => !$filter instanceof ActionFilter\Authentication,
		));
		$configureActions['showHtml']['-prefilters'][] = Engine\ActionFilter\CheckReadPermission::class;
		$configureActions['showHtml']['+prefilters'][] = new Engine\ActionFilter\CheckHtmlViewerReadPermission();
		$configureActions['showHtml']['+prefilters'][] = new ActionFilter\HttpMethod([
			ActionFilter\HttpMethod::METHOD_GET,
		]);
		$configureActions['showTiffPreview'] = $configureActions['showMarkdown'];
		$configureActions['showTiffPreview']['+prefilters'][] = new ActionFilter\HttpMethod([
			ActionFilter\HttpMethod::METHOD_GET,
		]);

		return $configureActions;
	}

	public function getPrimaryAutoWiredParameter()
	{
		return new ExactParameter(Disk\Version::class, 'version', function($className, $id){
			return Disk\Version::loadById($id);
		});
	}

	/**
	 * Returns default pre-filters for action.
	 * @return array
	 */
	protected function getDefaultPreFilters()
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new Engine\ActionFilter\CheckReadPermission();

		return $defaultPreFilters;
	}

	public function downloadAction(Disk\Version $version): ?Response\BFile
	{
		$file = $version->getObject();

		$securityContext = $file?->getStorage()?->getSecurityContext(
			user: $this->getCurrentUser()->getId(),
			shouldCheckUnifiedLink: $file instanceof Disk\File && $this->shouldCheckUnifiedLink($file),
		);

		if (!$file || !$securityContext || !$file->canDownload($securityContext))
		{
			$this->addError(new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED')));

			return null;
		}

		$response = Response\BFile::createByFileId($version->getFileId(), $version->getName());
		$response->setCacheTime(Disk\Configuration::DEFAULT_CACHE_TIME);

		return $response;
	}

	private function shouldCheckUnifiedLink(Disk\File $file): bool
	{
		$serviceLocator = \Bitrix\Main\DI\ServiceLocator::getInstance();
		$unifiedLinkUrl = $serviceLocator->get(Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl::class);
		$uls = $unifiedLinkUrl->getUlsFromRequest($this->request);

		if (!is_string($uls))
		{
			return false;
		}

		$unifiedLinkAccessService = $serviceLocator->get(Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService::class);

		return $unifiedLinkAccessService->validateUlsForObjectId(
			objectId: $file->getId(),
			uls: $uls,
		);
	}

	public function showMarkdownAction(Disk\Version $version): ?array
	{
		if (!Disk\Configuration::isEnabledMarkdownViewer())
		{
			$this->addError(new Error('Markdown viewer is disabled by configuration.', MarkdownRenderService::ERROR_VIEWER_DISABLED));

			return null;
		}

		$result = (new MarkdownRenderService())->renderByVersion($version);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function showHtmlAction(Disk\Version $version): HttpResponse
	{
		return ServiceLocator::getInstance()->get(HtmlViewerService::class)->showByVersion($version);
	}

	public function showTiffPreviewAction(
		Disk\Version $version,
		?string $previewToken = null,
	): array|Response\BFile|null
	{
		$result = (new TiffPreviewService())->getByVersion($version, $previewToken);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		return $data['response'] ?? $data;
	}

	public function deleteAction(Disk\Version $version)
	{
		$file = $version->getObject();
		$securityContext = $file->getStorage()->getSecurityContext($this->getCurrentUser()->getId());
		if (!$file->canManageVersions($securityContext))
		{
			$this->addDeniedError();

			return;
		}

		if (!$version->delete($this->getCurrentUser()->getId()))
		{
			$this->errorCollection->add($version->getErrors());

			return;
		}
	}
}
