<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\AiAssistant\Trigger\Repository\ChatMapRepository;
use Bitrix\AiAssistant\Trigger\Repository\TriggerRepository;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\Action\FileChatTrigger;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionDto;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
use Bitrix\Disk\Realtime\Tags\ObjectTag;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Render;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

class HtmlEditorPageService
{
	public const ERROR_SOURCE_NOT_FOUND = 'source_not_found';
	public const ERROR_HISTORICAL_VERSION = 'historical_version_not_editable';
	public const ERROR_UNSUPPORTED_FORMAT = 'unsupported_format';

	public function __construct(
		private readonly UnifiedLinkSignature $signature = new UnifiedLinkSignature(),
		private ?OpeningContextFactory $openingContextFactory = null,
		private ?HeaderCompositionService $compositionService = null,
	)
	{
	}

	public function buildParamsByFile(File $file): Result
	{
		return $this->buildParams($file, null);
	}

	public function buildParamsByAttachedObject(AttachedObject $attachedObject): Result
	{
		if ($attachedObject->isSpecificVersion())
		{
			return $this->error('Historical version is not editable.', self::ERROR_HISTORICAL_VERSION);
		}

		$file = $attachedObject->getFile();
		if (!$file instanceof File)
		{
			return $this->error('HTML editor source was not found.', self::ERROR_SOURCE_NOT_FOUND);
		}

		return $this->buildParams($file, $attachedObject);
	}

	public function renderPage(array $componentParams): string
	{
		$fileId = (int)($componentParams['SOURCE']['fileId'] ?? 0);
		$userId = (int)CurrentUser::get()->getId();
		if ($fileId > 0 && $userId > 0 && Loader::includeModule('pull'))
		{
			\CPullWatch::Add($userId, (new ObjectTag($fileId))->getName(), true);
		}

		if (Application::getInstance()->getContext()->getRequest()->get('IFRAME') !== 'Y')
		{
			return $this->renderStandalonePage($componentParams);
		}

		return (string)$GLOBALS['APPLICATION']->includeComponent('bitrix:ui.sidepanel.wrapper', '', [
			'RETURN_CONTENT' => true,
			'POPUP_COMPONENT_NAME' => 'bitrix:disk.file.editor-html',
			'POPUP_COMPONENT_TEMPLATE_NAME' => '',
			'POPUP_COMPONENT_PARAMS' => $componentParams,
			'PLAIN_VIEW' => true,
			'IFRAME_MODE' => true,
			'PREVENT_LOADING_WITHOUT_IFRAME' => false,
			'USE_PADDING' => false,
		]);
	}

	protected function renderStandalonePage(array $componentParams): string
	{
		if (Loader::includeModule('pull'))
		{
			\CPullOptions::OnEpilog();
		}

		return (new Render\Component(
			'bitrix:disk.file.editor-html',
			'',
			$componentParams,
			withSiteTemplate: true,
		))->getContent();
	}

	private function buildParams(File $file, ?AttachedObject $attachedObject): Result
	{
		$realFile = $file->getRealObject();
		if (!$realFile instanceof File)
		{
			return $this->error('HTML editor source was not found.', self::ERROR_SOURCE_NOT_FOUND);
		}

		if (!HtmlViewerPolicy::isViewableFile($realFile))
		{
			return $this->error('HTML editor source format is unsupported.', self::ERROR_UNSUPPORTED_FORMAT);
		}

		$urlManager = Driver::getInstance()->getUrlManager();
		$attachedObjectId = $attachedObject === null ? null : (int)$attachedObject->getId();
		$name = (string)($attachedObject?->getName() ?? $file->getName());
		$downloadUrl = $attachedObject === null
			? (string)(new Uri($urlManager->getUrlForDownloadFile($file)))->addParams([
				'_uls' => $this->signature->getUlsForObjectId((int)$file->getId()),
			])
			: $urlManager->getUrlUfController('download', ['attachedId' => $attachedObjectId])
		;
		$userId = (int)CurrentUser::get()->getId();
		$composition = null;
		try
		{
			$this->openingContextFactory ??= ServiceLocator::getInstance()->get(OpeningContextFactory::class);
			$context = $attachedObject === null
				? $this->openingContextFactory->fromHtmlEditorFile($file, $userId, $downloadUrl)
				: $this->openingContextFactory->fromHtmlEditorAttachedObject($attachedObject, $userId, $downloadUrl)
			;
			$composition = $this->getCompositionService()->composeForPage($context, $userId);
		}
		catch (\Throwable)
		{
			$composition = null;
		}
		if ($composition === null)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				new \RuntimeException('The html editor page was built with no description of its header.'),
			);
			$composition = $this->getCompositionService()->composeMinimalForPage(
				$name,
				$downloadUrl,
				HeaderCompositionDto::MODE_EDIT,
			);
		}

		$editorUrl = new Uri($urlManager->getUrlForHtmlEditor($file, $attachedObjectId));
		$busyUrl = (string)(new Uri($editorUrl->getPath()))->addParams([
			'attachedId' => $attachedObjectId,
			'htmlEditorState' => 'session_busy',
		]);

		return (new Result())->setData([
			'NAME' => $name,
			'HEADER_COMPOSITION' => $composition,
			'PORTAL_URL' => (string)$urlManager->getUrlForUserDisk($userId),
			'BUSY_URL' => $busyUrl,
			'SOURCE' => [
				'fileId' => (int)$realFile->getId(),
				'attachedObjectId' => $attachedObjectId,
				'uniqueCode' => (string)$realFile->getUniqueCode(),
			],
			'FILE_CHAT_ID' => ($composition['fileChat']['fileId'] ?? null) === (int)$realFile->getId()
				? $this->getExistingFileChatId($userId, (int)$realFile->getId())
				: null,
		]);
	}

	private function getExistingFileChatId(int $userId, int $fileId): ?int
	{
		if ($userId <= 0 || !Loader::includeModule('aiassistant'))
		{
			return null;
		}

		try
		{
			$triggerId = ServiceLocator::getInstance()->get(TriggerRepository::class)
				->getByCode(FileChatTrigger::getCode());
			if ($triggerId === null)
			{
				return null;
			}

			return (new ChatMapRepository())->findChatId($userId, $triggerId, (string)$fileId);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function getCompositionService(): HeaderCompositionService
	{
		return $this->compositionService ??= ServiceLocator::getInstance()->get(HeaderCompositionService::class);
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
