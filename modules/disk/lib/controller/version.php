<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Infrastructure\Controller\HtmlViewerRefusalResponse;
use Bitrix\Disk\Internal\Service\HtmlViewerService;
use Bitrix\Disk\Internal\Service\MarkdownRenderService;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;

final class Version extends Engine\Controller
{
	use HtmlViewerRefusalResponse;

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();

		$configureActions['download'] =
		$configureActions['showMarkdown'] =
		$configureActions['showHtml'] = [
			'-prefilters' => [
				ActionFilter\Csrf::class,
				ActionFilter\Authentication::class,
			],
			'+prefilters' => [
				new ActionFilter\Authentication(true),
				new ActionFilter\CloseSession(),
			]
		];

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

	public function downloadAction(Disk\Version $version): Response\BFile
	{
		$response = Response\BFile::createByFileId($version->getFileId(), $version->getName());
		$response->setCacheTime(Disk\Configuration::DEFAULT_CACHE_TIME);

		return $response;
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

	public function deleteAction(Disk\Version $version)
	{
		$file = $version->getObject();
		$securityContext = $file->getStorage()->getSecurityContext($this->getCurrentUser()->getId());
		if (!$file->canDelete($securityContext) || !$file->canRestore($securityContext))
		{
			$this->errorCollection[] = new Error(
				Loc::getMessage("DISK_CHECK_READ_PERMISSION_ERROR_MESSAGE")
			);

			return;
		}

		if (!$version->delete($this->getCurrentUser()->getId()))
		{
			$this->errorCollection->add($version->getErrors());

			return;
		}
	}
}