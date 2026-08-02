<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkFileRenderer;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\{FileTypeControl, RedirectToCorrectPrefix};
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessLevelRouter;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\{RedirectToView, UrlGenerator};
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\FileTypes;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\LevelAccess;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Request;

class UnifiedLinkController extends Controller
{
	private RequiredParameter $requiredParameterFilter;
	private UnifiedLinkAccessLevelRouter $accessControlFilter;
	private FileTypeControl $fileTypeFilter;
	private RedirectToCorrectPrefix $redirectToCorrectPrefix;

	public function __construct(?Request $request = null)
	{
		$this->requiredParameterFilter = new RequiredParameter('service');
		$this->fileTypeFilter = new FileTypeControl($this);
		$this->accessControlFilter = new UnifiedLinkAccessLevelRouter($this);
		$this->redirectToCorrectPrefix = new RedirectToCorrectPrefix($this);

		parent::__construct($request);
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			$this->requiredParameterFilter,
			new HttpMethod([HttpMethod::METHOD_GET]),
			$this->redirectToCorrectPrefix,
			$this->fileTypeFilter,
			$this->accessControlFilter,
		];
	}

	protected function getDefaultPostFilters(): array
	{
		return [
			$this->requiredParameterFilter,
			$this->redirectToCorrectPrefix,
			$this->fileTypeFilter,
			$this->accessControlFilter,
		];
	}

	/**
	 * @return ExactParameter[]
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				UnifiedLinkFileRenderer::class,
				'service',
				function ($className,  string $uniqueCode): ?UnifiedLinkFileRenderer {
					$file = File::loadByUniqueCode($uniqueCode);
					if (!$file)
					{
						return null;
					}
					$attachedObject = AttachedObject::loadById((int)$this->request->get('attachedId'));
					$version = Version::loadById((int)$this->request->get('versionId'));
					$analytics = $this->request->getQuery('analytics') ?? [];
					$currentUser = $this->getCurrentUser();
					$isDeferred = $this->request->get('immediate_load') !== 'Y';

					return new UnifiedLinkFileRenderer(
						file: $file,
						attachedObject: $attachedObject,
						version: $version,
						analytics: $analytics,
						currentUser: $currentUser,
						deferred: $isDeferred,
					);
				},
			),
		];
	}

	#[LevelAccess(UnifiedLinkAccessLevel::Read)]
	#[UrlGenerator([new UrlManager(), 'getUnifiedLink'])]
	#[FileTypes(
		TypeFile::IMAGE,
		TypeFile::VIDEO,
		TypeFile::DOCUMENT,
		TypeFile::ARCHIVE,
		TypeFile::SCRIPT,
		TypeFile::UNKNOWN,
		TypeFile::PDF,
		TypeFile::AUDIO,
		TypeFile::KNOWN,
		TypeFile::VECTOR_IMAGE,
		TypeFile::BOARD,
	)]
	public function viewAction(?UnifiedLinkFileRenderer $service): HttpResponse
	{
		return $this->createResponse($service, UnifiedLinkAccessLevel::Read);
	}

	#[LevelAccess(UnifiedLinkAccessLevel::Edit)]
	#[UrlGenerator([new UrlManager(), 'getUnifiedEditLink'])]
	#[FileTypes(TypeFile::DOCUMENT)]
	#[RedirectToView(
		TypeFile::IMAGE,
		TypeFile::VIDEO,
		TypeFile::ARCHIVE,
		TypeFile::SCRIPT,
		TypeFile::UNKNOWN,
		TypeFile::AUDIO,
		TypeFile::KNOWN,
		TypeFile::VECTOR_IMAGE,
		TypeFile::BOARD,
	)]
	public function editAction(?UnifiedLinkFileRenderer $service): HttpResponse
	{
		return $this->createResponse($service, UnifiedLinkAccessLevel::Edit);
	}

	/**
	 * @param UnifiedLinkFileRenderer|null $service
	 * @param UnifiedLinkAccessLevel $accessLevel
	 * @return HttpResponse
	 * @throws ArgumentTypeException
	 */
	private function createResponse(?UnifiedLinkFileRenderer $service, UnifiedLinkAccessLevel $accessLevel): HttpResponse
	{
		if ($service === null)
		{
			return (new HttpResponse())
				->setStatus(404)
				->setContent(UnifiedLinkFileRenderer::renderAccessDeniedPage())
			;
		}

		$result = $service->render($accessLevel);
		$redirectUrl = $result->getRedirectUrl();

		if(is_string($redirectUrl))
		{
			return $this->redirectTo($redirectUrl);
		}

		$component = $result->getComponent();
		if ($component !== null)
		{
			return $this->renderComponent($component, withSiteTemplate: false);
		}

		return (new HttpResponse())
			->setStatus($result->getStatus())
			->setContent($result->getContent())
		;
	}
}
