<?php

namespace Bitrix\Disk\Document;


use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Contract\CloudImportInterface;
use Bitrix\Disk\Document\Contract\FileCreatable;
use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Internal\Service\CustomServerConfigsMapper;
use Bitrix\Disk\Internal\Service\Document\OfficeEditorEntryHandler;
use Bitrix\Disk\Internal\Service\Document\OfficeEngineRecognizer;
use Bitrix\Disk\Internal\Service\OnlyOffice\Handlers\CustomOnlyOfficeServerHandler;
use Bitrix\Disk\Internal\Service\R7\Handlers\CustomR7ServerHandler;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Public\Provider\CustomServerAvailabilityProvider;
use Bitrix\Disk\Public\Provider\CustomServerProvider;
use Bitrix\Disk\UI\Viewer\Renderer\Board;
use Bitrix\Disk\UI\Viewer\Renderer\Markdown;
use Bitrix\Disk\User;
use Bitrix\Disk\UserConfiguration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

class DocumentHandlersManager
{
	const ERROR_UNKNOWN_HANDLER = 'DISK_DOC_HANDM_22001';

	/** @var DocumentHandler[] */
	protected $documentHandlerList = array();
	/** @var  ErrorCollection */
	protected $errorCollection;
	protected $userId;
	private ?DocumentHandler $defaultServiceForCurrentUser = null;
	protected CustomServerAvailabilityProvider $customServerAvailability;
	protected CustomServerProvider $customServerProvider;
	protected CustomServerConfigsMapper $customServerConfigsMapper;

	public function __construct($user)
	{
		$this->errorCollection = new ErrorCollection;
		$this->userId = User::resolveUserId($user);

		$this->customServerAvailability =
			ServiceLocator
				::getInstance()
				->get(CustomServerAvailabilityProvider::class)
		;

		$this->customServerProvider = ServiceLocator::getInstance()->get(CustomServerProvider::class);

		$this->customServerConfigsMapper =
			ServiceLocator
				::getInstance()
				->get(CustomServerConfigsMapper::class)
		;

		$this->buildDocumentHandlerList();
	}

	/**
	 * Get default cloud document service for current user.
	 * In this method we don't know about local controller.
	 * @return null|DocumentHandler
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getDefaultServiceForCurrentUser()
	{
		//todo may be we should use userId but now we look on $USER;
		if ($this->defaultServiceForCurrentUser)
		{
			return $this->defaultServiceForCurrentUser;
		}
		$codeForUser = UserConfiguration::getDocumentServiceCode();
		if(empty($codeForUser))
		{
			//todo by default we use googleHandler. But possible create option with default service.
			/** @var GoogleHandler $googleDriveClass */
			$googleDriveClass = GoogleHandler::className();
			$codeForUser = $googleDriveClass::getCode();
		}
		$this->defaultServiceForCurrentUser = $this->getSelectableHandlerByCode($codeForUser);

		return $this->defaultServiceForCurrentUser;
	}

	/**
	 * Gets the handler a user chooses by its code: the one of the registry, or the item of the office editor
	 * for the entry code while OnlyOffice is not registered and VibeOffice is enabled.
	 *
	 * @param string $code
	 * @return DocumentHandler|null
	 * @throws SystemException
	 */
	public function getSelectableHandlerByCode(string $code): ?DocumentHandler
	{
		if ($this->shouldStandForEntryCode($code))
		{
			return new OfficeEditorEntryHandler($this->userId);
		}

		return $this->getHandlerByCode($code);
	}

	/**
	 * The office editor keeps its entry code when OnlyOffice is not registered and VibeOffice opens the documents
	 * (ADR 3.10).
	 */
	private function shouldStandForEntryCode(string $code): bool
	{
		return
			OfficeEngineRecognizer::isEntryCode($code)
			&& !$this->isRegisteredHandlerCode($code)
			&& VibeofficeHandler::isEnabled()
		;
	}

	/**
	 * Gets document handler by code.
	 * @param string $code
	 * @return DocumentHandler|null
	 * @throws SystemException
	 */
	public function getHandlerByCode($code)
	{
		if(!isset($this->documentHandlerList[$code]))
		{
			$this->errorCollection->add(array(new Error("Unknown document handler name {$code}", self::ERROR_UNKNOWN_HANDLER)));

			return null;
		}

		/** @var DocumentHandler $documentHandler */
		$documentHandler = new $this->documentHandlerList[$code]($this->userId);
		if(!$documentHandler instanceof DocumentHandler)
		{
			throw new SystemException("Invalid class '{$this->documentHandlerList[$code]}' for documentHandler. Must be instance of DocumentHandler");
		}

		return $documentHandler;
	}

	/**
	 * Resolves the effective document handler for a dispatch request. This is the single seam where the engine
	 * is chosen, in this order (ADR 3.10):
	 *
	 * 1. a server of the client — its own code, or the entry code while it is the portal viewer — is returned
	 *    as is;
	 * 2. the entry code or another handler of the OnlyOffice family is translated to the {@see VibeofficeHandler}
	 *    when vibeoffice is enabled for the context, even if OnlyOffice is not registered;
	 * 3. otherwise the handler is returned as is (including null for an unknown code).
	 *
	 * The translation is narrowed by the project cohort through {@see Vibeoffice\Feature::isEnabledForContext()}.
	 * An empty allowlist keeps the feature portal-wide.
	 *
	 * @param string $serviceCode
	 * @param DocumentResolveContext|null $context
	 * @return DocumentHandler|null
	 * @throws SystemException
	 */
	public function resolveEffectiveHandler(string $serviceCode, ?DocumentResolveContext $context = null): ?DocumentHandler
	{
		$isEntryCode = OfficeEngineRecognizer::isEntryCode($serviceCode);
		$isClientServer =
			OfficeEngineRecognizer::isClientServerCode($serviceCode)
			|| ($isEntryCode && $this->isClientServerSelected())
		;
		if ($isClientServer)
		{
			return $this->getHandlerByCode($serviceCode);
		}

		if (
			($isEntryCode || $this->isOnlyOfficeFamilyCode($serviceCode))
			&& VibeofficeHandler::isEnabled()
			&& Vibeoffice\Feature::isEnabledForContext($context)
		)
		{
			return $this->getHandlerByCode(VibeofficeHandler::getCode());
		}

		return $this->getHandlerByCode($serviceCode);
	}

	/**
	 * Whether the portal viewer is a custom server of the client that is ready for use. Such a server exists
	 * only on a box portal.
	 */
	public function isClientServerSelected(): bool
	{
		if (Configuration::getDefaultViewerServiceCode(false) !== OnlyOfficeHandler::getCode())
		{
			return false;
		}

		$customConfigType = Configuration::getDefaultViewerCustomConfigType();
		if ($customConfigType === null)
		{
			return false;
		}

		$customServer = $this->customServerProvider->getFirstByType($customConfigType);
		if (!$customServer instanceof CustomServerInterface)
		{
			return false;
		}

		return $this->customServerAvailability->isAvailableCustomServerForUse($customServer);
	}

	private function isOnlyOfficeFamilyCode(string $code): bool
	{
		return
			$this->isRegisteredHandlerCode($code)
			&& is_a($this->documentHandlerList[$code], OnlyOfficeHandler::class, true)
		;
	}

	/**
	 * Maps the SERVICE of an already existing document session to the editor shell component name.
	 *
	 * The source of truth is the session's own SERVICE, NOT {@see VibeofficeHandler::isEnabled()}:
	 * this keeps rendering by an existing session correct even when the portal flag was just turned
	 * off after the session had been created as vibeoffice. With the flag off no vibeoffice session
	 * can exist, so the SERVICE is never vibeoffice and the onlyoffice shell is chosen (zero regression).
	 *
	 * @param DocumentService|null $service
	 * @return string
	 */
	public static function resolveShellComponentName(?DocumentService $service): string
	{
		return $service === DocumentService::Vibeoffice
			? 'bitrix:disk.file.editor-vibeoffice'
			: 'bitrix:disk.file.editor-onlyoffice';
	}

	/**
	 * Returns all list of document handlers.
	 * @return DocumentHandler[]
	 */
	public function getHandlers()
	{
		$list = [];
		foreach ($this->documentHandlerList as $code => $class)
		{
			$handler = $this->getHandlerByCode($code);
			if (!$this->shouldHideGoogle($handler))
			{
				$list[$code] = $handler;
			}
		}

		return $list;
	}

	/**
	 * @return string[]
	 */
	public function getRegisteredOfficeEngineCodes(): array
	{
		$codes = [];
		foreach ($this->documentHandlerList as $code => $handlerClass)
		{
			if (is_a($handlerClass, OnlyOfficeHandler::class, true) || is_a($handlerClass, VibeofficeHandler::class, true))
			{
				$codes[] = $code;
			}
		}

		return $codes;
	}

	private function shouldHideGoogle(DocumentHandler $handler): bool
	{
		return false;
	}

	private function shouldHideGoogleFromImport(DocumentHandler $handler): bool
	{
		return false;
	}

	/**
	 * Handlers eligible for user-facing "create/open with" pickers:
	 * {@see FileCreatable} handlers minus internal engines
	 * ({@see DocumentHandler::isSelectableForCreation()}), and the item of the office editor under the entry
	 * code while OnlyOffice is not registered and VibeOffice is enabled.
	 *
	 * @return DocumentHandler[] keyed by handler code
	 */
	public function getHandlersForCreatingFile(): array
	{
		$list = [];
		$entryCode = OnlyOfficeHandler::getCode();
		if ($this->shouldStandForEntryCode($entryCode))
		{
			$list[$entryCode] = new OfficeEditorEntryHandler($this->userId);
		}

		foreach ($this->getHandlers() as $code => $handler)
		{
			if ($handler instanceof FileCreatable && $handler::isSelectableForCreation())
			{
				$list[$code] = $handler;
			}
		}

		return $list;
	}

	/**
	 * Returns all list of document handlers which can import files and folders.
	 * @return DocumentHandler[]|CloudImportInterface[]
	 */
	public function getHandlersForImport()
	{
		$list = [];
		foreach ($this->getHandlers() as $code => $handler)
		{
			if ($handler instanceof CloudImportInterface)
			{
				if ($this->shouldHideByZone($handler))
				{
					continue;
				}
				if ($this->shouldHideGoogleFromImport($handler))
				{
					continue;
				}

				$list[$code] = $handler;
			}
		}

		return $list;
	}

	protected function getPortalZone(): ?string
	{
		$portalPrefix = null;
		if (Loader::includeModule('bitrix24'))
		{
			$portalPrefix = \CBitrix24::getLicensePrefix();
		}
		elseif (Loader::includeModule('intranet'))
		{
			$portalPrefix = \CIntranetUtils::getPortalZone();
		}

		if (!$portalPrefix)
		{
			return null;
		}

		return $portalPrefix;
	}

	protected function shouldHideByZone(DocumentHandler $handler): bool
	{
		if (!($handler instanceof YandexDiskHandler))
		{
			return false;
		}

		$zone = $this->getPortalZone();

		return !in_array($zone, ['ru', 'kz', 'by'], true);
	}

	/**
	 * Returns all list of document handlers which can view files.
	 *
	 * @return DocumentHandler[]
	 */
	public function getHandlersForView(): array
	{
		$list = array();
		foreach($this->getHandlers() as $code => $handler)
		{
			if(
				$handler instanceof IViewer &&
				(
					$code !== OnlyOfficeHandler::getCode() ||
					OnlyOfficeHandler::isEnabled(true)
				) &&
				(
					$code !== VibeofficeHandler::getCode() ||
					VibeofficeHandler::isEnabled()
				)
			)
			{
				$list[$code] = $handler;
			}
		}
		unset($handler);

		return $list;
	}

	/**
	 * Returns default document handler for view files.
	 *
	 * A registered handler that cannot view and an unknown handler code both fall back to the Bitrix viewer.
	 * This method is reached from the page of an external link before the password is checked, so it must not
	 * rewrite the portal setting.
	 *
	 * @return DocumentHandler
	 * @throws \Bitrix\Main\NotImplementedException
	 */
	public function getDefaultHandlerForView()
	{
		$serviceCode = Configuration::getDefaultViewerServiceCode();
		// The saved entry code is kept for the portal to return to OnlyOffice once it is enabled again.
		if ($this->shouldStandForEntryCode($serviceCode))
		{
			return $this->getHandlerByCode(VibeofficeHandler::getCode());
		}

		$documentHandler = $this->getViewerHandlerByCode($serviceCode);
		if ($documentHandler instanceof IViewer)
		{
			return $documentHandler;
		}

		return new BitrixHandler($this->userId);
	}

	/**
	 * A saved code is unusable in two ways: it may be missing from the registry, or it may belong to a
	 * handler that does not support viewing. Both answer with null, and the caller tells them apart by
	 * the registry itself: the error collection carries the errors of every earlier call as well.
	 */
	private function getViewerHandlerByCode(string $code): ?DocumentHandler
	{
		try
		{
			$documentHandler = $this->getHandlerByCode($code);
		}
		catch (SystemException $exception)
		{
			$this->errorCollection->add([new Error($exception->getMessage(), self::ERROR_UNKNOWN_HANDLER)]);

			return null;
		}

		return $documentHandler instanceof IViewer ? $documentHandler : null;
	}

	private function isRegisteredHandlerCode(string $code): bool
	{
		return isset($this->documentHandlerList[$code]);
	}

	public function isReady(DocumentHandler $documentHandler)
	{
		if(!$documentHandler->checkAccessibleTokenService())
		{
			$this->errorCollection->add($documentHandler->getErrors());
			return false;
		}

		return
			$documentHandler->queryAccessToken()->hasAccessToken() &&
			!$documentHandler->isRequiredAuthorization()
		;
	}

	protected function buildDocumentHandlerList()
	{
		$this->documentHandlerList = [];
		$r7CustomServer = $this->customServerProvider->getFirstByType(CustomServerTypes::R7);
		$onlyOfficeCustomServer = $this->customServerProvider->getFirstByType(CustomServerTypes::OnlyOffice);

		$isCustomR7Enabled =
			$r7CustomServer instanceof CustomServerInterface &&
			$this->customServerAvailability->isAvailableCustomServerForView($r7CustomServer)
		;

		$isCustomOnlyOfficeEnabled =
			$onlyOfficeCustomServer instanceof CustomServerInterface &&
			$this->customServerAvailability->isAvailableCustomServerForView($onlyOfficeCustomServer)
		;

		if (OnlyOfficeHandler::isEnabled(true) || $isCustomR7Enabled || $isCustomOnlyOfficeEnabled)
		{
			$this->documentHandlerList[OnlyOfficeHandler::getCode()] = OnlyOfficeHandler::class;
		}

		if(Flipchart\Configuration::isBoardsEnabled())
		{
			$this->documentHandlerList[BoardsHandler::getCode()] = BoardsHandler::class;
		}

		if (VibeofficeHandler::isEnabled())
		{
			$this->documentHandlerList[VibeofficeHandler::getCode()] = VibeofficeHandler::class;
		}

		$this->documentHandlerList[BitrixHandler::getCode()] = BitrixHandler::class;
		$this->documentHandlerList[GoogleHandler::getCode()] = GoogleHandler::class;
		$this->documentHandlerList[OneDriveHandler::getCode()] = OneDriveHandler::class;
		$this->documentHandlerList[Office365Handler::getCode()] = Office365Handler::class;
		$this->documentHandlerList[DropboxHandler::getCode()] = DropboxHandler::class;
		$this->documentHandlerList[GoogleViewerHandler::getCode()] = GoogleViewerHandler::class;
		$this->documentHandlerList[YandexDiskHandler::getCode()] = YandexDiskHandler::class;
		$this->documentHandlerList[BoxHandler::getCode()] = BoxHandler::class;

		if (MyOfficeHandler::isEnabled() && MyOfficeHandler::getPredefinedUser($this->userId))
		{
			$this->documentHandlerList[MyOfficeHandler::getCode()] = MyOfficeHandler::class;
		}

		if ($isCustomR7Enabled)
		{
			CustomR7ServerHandler::setCustomServer($r7CustomServer);

			$this->documentHandlerList[CustomR7ServerHandler::getCode()] = CustomR7ServerHandler::class;
		}

		if ($isCustomOnlyOfficeEnabled)
		{
			CustomOnlyOfficeServerHandler::setCustomServer($onlyOfficeCustomServer);

			$this->documentHandlerList[CustomOnlyOfficeServerHandler::getCode()] = CustomOnlyOfficeServerHandler::class;
		}

		$event = new Event(Driver::INTERNAL_MODULE_ID, 'onDocumentHandlerBuildList');
		$event->send();
		if($event->getResults())
		{
			foreach($event->getResults() as $evenResult)
			{
				if($evenResult->getType() != EventResult::SUCCESS)
				{
					continue;
				}
				$result = $evenResult->getParameters();
				if(!is_array($result))
				{
					throw new SystemException('Wrong event result by building DocumentHandlerList. Must be array.');
				}
				if(empty($result['CODE_NAME']))
				{
					throw new SystemException('Wrong event result by building DocumentHandlerList. Could not find CODE_NAME.');
				}
				if(empty($result['CLASS']))
				{
					throw new SystemException('Wrong event result by building DocumentHandlerList. Could not find CLASS.');
				}
				if(is_string($result['CLASS']) && class_exists($result['CLASS']))
				{
					$this->documentHandlerList[$result['CODE_NAME']] = $result['CLASS'];
				}
				else
				{
					throw new SystemException('Wrong event result by building DocumentHandlerList. Could not find class by CLASS.');
				}
			}
		}
	}

	/**
	 * @return Error[]
	 */
	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * @inheritdoc
	 */
	public function getErrorsByCode($code)
	{
		return $this->errorCollection->getErrorsByCode($code);
	}

	/**
	 * @inheritdoc
	 */
	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	public function getDefaultViewerServiceForView(): ?string
	{
		$code = Configuration::getDefaultViewerServiceCode();

		if (!is_string($code))
		{
			return null;
		}

		return $this->getCustomHandlerCodeByNormalCode($code) ?? $code;
	}

	public static function additionalPreviewManagersList(Event $event)
	{
		$renderersList = [
			Board::class,
		];

		if (Configuration::isEnabledMarkdownViewer())
		{
			$renderersList[] = Markdown::class;
		}

		$event->addResult(new EventResult(EventResult::SUCCESS, $renderersList));
	}

	protected function getCustomHandlerCodeByNormalCode(string $code): ?string
	{
		$customConfigType = Configuration::getDefaultViewerCustomConfigType();

		if (!$customConfigType instanceof CustomServerTypes)
		{
			return null;
		}

		$customServer = $this->customServerProvider->getFirstByType($customConfigType);

		if (!$customServer instanceof CustomServerInterface)
		{
			return null;
		}

		if (!$this->customServerAvailability->isAvailableCustomServerForView($customServer))
		{
			return null;
		}

		return $this->customServerConfigsMapper->getForNormalCodes()[$code][$customConfigType->value] ?? null;
	}
}
