<?php

namespace Bitrix\ImMobile\NavigationTab;

use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Folder\Eligibility\EligibleChatTypeDictionary;
use Bitrix\Im\V2\Folder\FolderLimits;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Integration\AI\CopilotNameResolver;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\EventManager;
use DateTimeInterface;
use CCloudStorageBucket;
use CSmile;
use CUser;
use Bitrix\Mobile;
use Bitrix\MobileApp;
use Bitrix\Im\V2\Chat\GeneralChat;
use Bitrix\Im\V2\Chat\InputAction\StatusMessageProvider;
use Bitrix\Im\V2\Chat\InputAction\Platform;
use Bitrix\ImMobile\Public\Feature\AudioPlayerV2Flag;
use Bitrix\ImMobile\Settings;
use Bitrix\ImMobile\User;
use Bitrix\Im\Integration\Imopenlines\Localize;
use Bitrix\Im\Integration\Imopenlines;
use Bitrix\Intranet\Invitation;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AI\EngineManager;
use Bitrix\Im\V2\Integration\AI\GreetingProvider;
use Bitrix\Im\V2\Integration\AI\SuggestProvider;

class Manager
{
	use MessengerComponentTitle;

	private Mobile\Context $context;

	/** @var Tab\BaseRecent[]|null */
	private ?array $sortedItemsCache = null;
	private ?FolderCollection $folderCollectionCache = null;

	public function __construct(Mobile\Context $context)
	{
		$this->context = $context;
	}

	public static function getShortTitle()
	{
		return Loc::getMessage('IMMOBILE_NAVIGATION_TAB_SHORT_TITLE');
	}

	public function getComponent(): array
	{
		$sortedTabs = $this->getSortedItems();
		/**
		 * @type Tab\BaseRecent[] $items
		 */
		$items = array_values(
			array_filter($sortedTabs, fn ($item) => $this->isRenderableTab($item))
		);

		$params = $this->getMessengerV2Params($sortedTabs);
		//TODO add cache name by md5 by params;
		$itemsData = [];
		foreach ($items as $item)
		{
			if (!Settings::isOpenlinesInMessengerV2Available() && $item->getId() === 'openlines')
			{
				$openLinesParams = $this->getMessengerParams($items);
				$item->mergeParams($openLinesParams['SHARED_PARAMS']);
				$itemsData[] = $item->getComponentData();

				continue;
			}

			$itemsData[] = $item->getWidgetData();
		}


		$encodedParams = '';
		try
		{
			$encodedParams = json_encode($params);
		}
		catch (\Throwable $error)
		{

		}

		return [
			'sort' => 100,
			'imageName' => 'chat',
			'cacheId' => $this->getCacheId($encodedParams),
			'badgeCode' => 'messages',
			'component' => [
				'name' => 'JSStackComponent',
				'title' => Loc::getMessage('MD_COMPONENT_IM_RECENT'),
				'componentCode' => 'im.messenger',
				'scriptPath' => MobileApp\Janative\Manager::getComponentPath('im:messenger'),
				'params' => $params,
				'rootWidget' => [
					'name' => 'tabs',
					'settings' => [
						'code' => 'im.tabs',
						'preload' => true,
						'objectName' => 'tabs',
						'titleParams'=> [
							'text' => $this->getTitle(),
							'type' => 'section',
						],
						'grabTitle' => false,
						'grabButtons' => true,
						'tabs' => [
							'items' => $itemsData,
						],
					],
				],
			],
		];
	}

	/**
	 * Whether the tab really gets into the tabs widget. The only source of this rule:
	 * both the widget items and FIRST_TAB_ID are built with it.
	 */
	private function isRenderableTab(Tab\BaseRecent $tab): bool
	{
		return $tab->isWidgetAvailable()
			|| ($tab->getId() === 'openlines' && $tab->isAvailable())
		;
	}

	/**
	 * @param Tab\BaseRecent[] $tabs tabs in server order (SORT ASC, ID ASC)
	 */
	private function resolveFirstTabId(array $tabs): string
	{
		foreach ($tabs as $tab)
		{
			if ($this->isRenderableTab($tab))
			{
				return $tab->getId();
			}
		}

		return '';
	}

	private function getCacheId($additionalString = ''): string
	{
		$folders = $this->getFolderCollection();
		if ($folders->count() > 0)
		{
			$folderIds = [];
			foreach ($folders as $folder)
			{
				if ($folder->getId() !== null)
				{
					$folderIds[] = $folder->getId();
				}
			}
			return 'chat_tabs_folders_' . hash('sha256', implode(',', $folderIds) . $additionalString);
		}

		// fallback: preset-mode
		$sortedTabs = $this->getSortedItems();
		$enabledIds = [];
		foreach ($sortedTabs as $tab)
		{
			if ($tab->isAvailable())
			{
				$enabledIds[] = $tab->getId();
			}
		}
		sort($enabledIds);
		return 'chat_tabs_' . hash('sha256', implode('_', $enabledIds) . $additionalString);
	}

	/**
	 * @return Tab\BaseRecent[]
	 */
	private function getSortedItems(): array
	{
		if ($this->sortedItemsCache !== null)
		{
			return $this->sortedItemsCache;
		}

		$this->sortedItemsCache = $this->buildSortedItems();

		return $this->sortedItemsCache;
	}

	/**
	 * @return Tab\BaseRecent[]
	 */
	private function buildSortedItems(): array
	{
		if ($this->context->isGuest)
		{
			return $this->getGuestPreset();
		}

		$folders = $this->getFolderCollection();
		if ($folders->count() === 0)
		{
			return $this->getDefaultPresetByContext();
		}

		$tabs = [];
		foreach ($folders as $folder)
		{
			$folderId = $folder->getId();
			if ($folderId === null || !$folder->isVisible())
			{
				continue;
			}

			if (!($folder instanceof SystemFolder))
			{
				$tabs[] = new Tab\Folder($folder);

				continue;
			}

			$tab = $this->createSystemTab($folder->getCode(), $folder);
			// a system folder whose code the app does not know must not become a personal folder tab
			if ($tab === null)
			{
				continue;
			}

			$tabs[] = $tab;
		}

		return $tabs;
	}

	private function getGuestPreset(): array
	{
		return [
			$this->createSystemTab('default'),
		];
	}

	private function getDefaultPresetByContext(): array
	{
		if ($this->isLinesOperator())
		{
			if ((new Mobile\Tab\Manager())->getPresetName() === 'crm')
			{
				return $this->getCrmOpenLineOperatorPreset();
			}

			return $this->getOpenLineOperatorPreset();
		}

		return $this->getDefaultPreset();
	}

	private function createSystemTab(string $code, ?\Bitrix\Im\V2\Folder\Folder $folder = null): ?Tab\BaseRecent
	{
		return match ($code) {
			'all'         => new Tab\All($folder),
			'default'     => new Tab\Messenger($folder),
			'tasksTask'   => new Tab\Task($folder),
			'copilot'     => new Tab\Copilot($folder),
			'openChannel' => new Tab\Channel($folder),
			'collab'      => new Tab\Collab($folder),
			'openlines'   => new Tab\OpenLines($this->context, $folder),
			'vibecodeApp' => new Tab\VibecodeApp($folder),
			default       => null,
		};
	}

	private function getCrmOpenLineOperatorPreset(): array
	{
		return [
			$this->createSystemTab('openlines'),
			$this->createSystemTab('default'),
			$this->createSystemTab('tasksTask'),
			$this->createSystemTab('copilot'),
			$this->createSystemTab('openChannel'),
			$this->createSystemTab('collab'),
			$this->createSystemTab('all'),
		];
	}

	private function getOpenLineOperatorPreset(): array
	{
		return [
			$this->createSystemTab('default'),
			$this->createSystemTab('openlines'),
			$this->createSystemTab('tasksTask'),
			$this->createSystemTab('copilot'),
			$this->createSystemTab('openChannel'),
			$this->createSystemTab('collab'),
			$this->createSystemTab('all'),
		];
	}

	private function getDefaultPreset(): array
	{
		return [
			$this->createSystemTab('default'),
			$this->createSystemTab('tasksTask'),
			$this->createSystemTab('copilot'),
			$this->createSystemTab('openChannel'),
			$this->createSystemTab('collab'),
			$this->createSystemTab('openlines'),
			$this->createSystemTab('all'),
		];
	}

	/**
	 * @param Tab\BaseRecent[] $tabs
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function getMessengerV2Params(array $tabs): array
	{
		$availableTabs = [];
		foreach ($tabs as $tab)
		{
			$availableTabs[$tab->getId()] = $tab->isWidgetAvailable();
		}

		return [
			...$this->getSharedParams($tabs),
			'COMPONENT_CODE' => 'im.messenger',
			'AVAILABLE_TABS' => $availableTabs,
		];
	}

	/**
	 * @param Tab\BaseRecent[] $tabs
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function getMessengerParams(array $tabs): array
	{
		return [
			'SHARED_PARAMS' => [
				...$this->getSharedParams($tabs),
				'WIDGET_CHAT_CREATE_VERSION' => MobileApp\Janative\Manager::getComponentVersion('im:im.chat.create'),
				'WIDGET_CHAT_USERS_VERSION' => MobileApp\Janative\Manager::getComponentVersion('im:im.chat.user.list'),
				'WIDGET_CHAT_RECIPIENTS_VERSION' => MobileApp\Janative\Manager::getComponentVersion('im:im.chat.user.selector'),
				'WIDGET_CHAT_TRANSFER_VERSION' => MobileApp\Janative\Manager::getComponentVersion('im:im.chat.transfer.selector'),
				'WIDGET_BACKDROP_MENU_VERSION' => MobileApp\Janative\Manager::getComponentVersion('backdrop.menu'),
				...$this->getInvitationParams(),
			],
		];
	}

	/**
	 * @param Tab\BaseRecent[] $tabs
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function getSharedParams(array $tabs): array
	{
		$permissions = [];
		$folders = (object)[];
		if (Loader::includeModule('im'))
		{
			$permissionManager = new \Bitrix\Im\V2\Permission(true);
			$permissions = [
				'byChatType' => $permissionManager->getByChatTypes(),
				'byUserType' => $permissionManager->getByUserTypes(),
				'actionGroups' => $permissionManager->getActionGroupDefinitions(),
				'actionGroupsDefaults' => $permissionManager->getDefaultPermissionForGroupActions(),
			];
			$folders = [
				'allowedTypes' => EligibleChatTypeDictionary::forMobile(),
				'limits' => [
					'maxFolders' => FolderLimits::MAX_FOLDERS_PER_USER,
					'maxChatsPerFolder' => FolderLimits::MAX_CHATS_PER_FOLDER,
					'maxTitleLength' => FolderLimits::MAX_TITLE_LENGTH,
				],
			];
		}

		$isCloud = ModuleManager::isModuleInstalled('bitrix24') && defined('BX24_HOST_NAME');

		$hasActiveBucket = false;
		if (Loader::includeModule('clouds'))
		{
			$buckets = CCloudStorageBucket::getAllBuckets();
			foreach ($buckets as $bucket)
			{
				if ($bucket['ACTIVE'] === 'Y' && $bucket['READ_ONLY'] !== 'Y')
				{
					$hasActiveBucket = true;
					break;
				}
			}
		}

		$firstTabId = $this->resolveFirstTabId($tabs);

		$enableDevWorkspace = false;
		if (Option::get('mobile', 'developers_menu_section', 'N') === 'Y')
		{
			foreach (EventManager::getInstance()->findEventHandlers("mobileapp", "onJNComponentWorkspaceGet", ['immobile']) as $event)
			{
				if ($event['TO_METHOD'] === 'getImmobileJNDevWorkspace')
				{
					$enableDevWorkspace = true;
				}
			}
		}

		$copilot = null;
		if ((new Tab\Copilot())->isAvailable())
		{
			$copilot = $this->getCopilotData();
		}

		return [
			'USER_ID' => $this->context->userId,
			'SITE_ID' => $this->context->siteId,
			'SITE_DIR' => $this->context->siteDir,
			'STARTUP_FOLDER_LIST' => $this->getStartupFolderList(),
			'LANGUAGE_ID' => LANGUAGE_ID,
			'LIMIT_ONLINE' => CUser::GetSecondsForLimitOnline(),
			'IM_GENERAL_CHAT_ID' => GeneralChat::getGeneralChatId(),
			'SEARCH_MIN_SIZE' => Helper::getMinTokenSize()?: 3,
			'OPENLINES_USER_IS_OPERATOR' => (new Tab\OpenLines($this->context))->isAvailable(),

			'COMPONENT_CHAT_DIALOG_VERSION' => Mobile\WebComponentManager::getWebComponentVersion('im.dialog'),
			'COMPONENT_CHAT_DIALOG_VUE_VERSION' => Mobile\WebComponentManager::getWebComponentVersion('im.dialog.vue'),
			'IS_NETWORK_SEARCH_AVAILABLE' => $this->isNetworkSearchAvailable(),
			'IS_DEVELOPMENT_ENVIRONMENT' => $this->isDevelopmentEnvironment(),
			'MESSAGES' => [
				'IMOL_CHAT_ANSWER_M' => Localize::get(Localize::FILE_LIB_CHAT, "IMOL_CHAT_ANSWER_M"),
				'IMOL_CHAT_ANSWER_F' => Localize::get(Localize::FILE_LIB_CHAT, "IMOL_CHAT_ANSWER_F"),
				'AI_ASSISTANT' => $this->getAiAssistantStatusMessages(),
				'NAVIGATION_TAB_TITLES' => $this->getNavigationTabTitles(),
				'COPILOT_SUGGESTS' => $this->getCopilotSuggests(),
				'COPILOT_GREETINGS' => $this->getCopilotGreetings(),
				'COPILOT_GREETING_WEEK_HOLIDAYS' => $this->getCopilotGreetingWeekHolidays(),
			],
			'IS_CLOUD' => $isCloud,
			'HAS_ACTIVE_CLOUD_STORAGE_BUCKET' => $hasActiveBucket,
			'IS_BETA_AVAILABLE' => Settings::isBetaAvailable(),
			'IS_COPILOT_SELECT_MODEL_ENABLED' => Settings::isCopilotSelectModelEnabled(),
			'IS_CHAT_LOCAL_STORAGE_AVAILABLE' => Settings::isChatLocalStorageAvailable(),
			'IS_MARKDOWN_PARSER_ENABLED' => Settings::isMarkdownParserEnabled(),
			'IS_MARKDOWN_NATIVE_RENDERER_AVAILABLE' => Settings::isMarkdownNativeRendererAvailable(),
			'IS_OPENLINES_IN_MESSENGER_V2_AVAILABLE' => Settings::isOpenlinesInMessengerV2Available(),
			'IS_TASKS_RECENT_LIST_AVAILABLE' => Settings::isTasksRecentListAvailable(),
			'IS_MARKET_AVAILABLE' => Settings::isMarketAvailable(),
			'IS_VIBECODE_BUTTON_AVAILABLE' => Settings::isVibecodeButtonAvailable(),
			'IS_VIBECODE_RECENT_ACTION_AVAILABLE' => Settings::isVibecodeRecentActionAvailable(),
			'IS_VIBECODE_NEW_APPS_BADGE_AVAILABLE' => Settings::isVibecodeNewAppsBadgeAvailable(),
			'IS_VIBECODE_APP_CHAT_SEND_PERMISSION_AVAILABLE' => Settings::isVibecodeAppChatSendPermissionAvailable(),
			'IS_AUTO_TASKS_ENABLED' => Settings::isAutoTaskEnabled(),
			'IS_AUTO_TASKS_UI_AVAILABLE' => Settings::isAutoTaskUIAvailable(),
			'IS_VIDEO_NOTE_PLAYBACK_RATE_AVAILABLE' => Settings::isVideoNotePlaybackRateAvailable(),
			'SMILE_LAST_UPDATE_DATE' => CSmile::getLastUpdate()->format(DateTimeInterface::ATOM),
			'CAN_USE_TELEPHONY' => Loader::includeModule('voximplant') && \Bitrix\Voximplant\Security\Helper::canCurrentUserPerformCalls(),
			'FIRST_TAB_ID' => $firstTabId,
			'HUMAN_RESOURCES_STRUCTURE_AVAILABLE' => Structure::isSyncAvailable() ? 'Y' : 'N',
			'ENABLE_DEV_WORKSPACE' => $enableDevWorkspace ? 'Y' : 'N',
			'PLAN_LIMITS' => Settings::planLimits(),
			'IM_FEATURES' => Settings::getImFeatures(),
			'USER_INFO' => [
				'id' => User::getCurrent()?->getId() ?? 0,
				'type' => User::getCurrent()?->getType()?->value ?? 'user',
			],
			'REQUEST_GUEST_NAME' => $this->context->requestGuestName,
			'GUEST_CODE' => $this->context->guestCode,
			'PERMISSIONS' => $permissions,
			'FOLDERS' => $folders,
			'MULTIPLE_ACTION_MESSAGE_LIMIT' => Settings::getMultipleActionMessageLimit(),
			'CALL_SERVER_MAX_USERS' => $this->getCallServerMaxUsers(),
			'SERVICE_HEALTH_URL' => $this->getServiceHealthUrl(),
			'VIDEO_CALLS_TERMS_URL' => $this->getVideoCallsTermsUrl(),
			'AI_SETTINGS' => [
				'MAX_TRANSCRIBABLE_FILE_SIZE' => $this->getMaxTranscribableFileSize(),
			],
			'CAN_USE_AUDIO_PANEL' => $this->canUseAudioPanel(),
			'COPILOT_DATA' => $copilot,
			'COPILOT_AVAILABLE_ENGINES' => $this->getAvailableEngines(),
			'COPILOT_BOT_NAME' => $this->getCopilotBotName(),
			'COPILOT_AGENT_NAME' => $this->getCopilotAgentName(),
			'COPILOT_FILE_UPLOAD_POLICY' => $this->getCopilotFileUploadPolicy(),
		];
	}

	private function getCopilotFileUploadPolicy(): ?array
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		return CopilotChat::getFileUploadPolicyPublicData();
	}

	private function getFolderCollection(): FolderCollection
	{
		if ($this->folderCollectionCache !== null)
		{
			return $this->folderCollectionCache;
		}

		// guests never see folder tabs; skip folder bootstrap (ensureSystemFolders) for them
		if ($this->context->isGuest)
		{
			$this->folderCollectionCache = new FolderCollection();

			return $this->folderCollectionCache;
		}

		$userId = (int)$this->context->userId;
		$this->folderCollectionCache = ServiceLocator::getInstance()
			->get(FolderProvider::class)
			->getByUser($userId)
			->onlyAvailable($userId)
		;

		return $this->folderCollectionCache;
	}

	private function getStartupFolderList(): array
	{
		return $this->getFolderCollection()->toRestFormat();
	}

	/**
	 * @deprecated
	 */
	private function getInvitationParams(): array
	{
		$isIntranetInvitationAdmin = (
			Loader::includeModule('intranet')
			&& Invitation::canListDelete()
		);

		$canInvite = (
			Loader::includeModule('intranet')
			&& Invitation::canCurrentUserInvite()
		);

		$registerUrl = (
		$canInvite
			? Invitation::getRegisterUrl()
			: ''
		);

		$registerAdminConfirm = (
		$canInvite
			? Invitation::getRegisterAdminConfirm()
			: 'N'
		);

		$disableRegisterAdminConfirm = !Invitation::canListDelete();

		$registerSharingMessage = (
		$canInvite
			? Invitation::getRegisterSharingMessage()
			: ''
		);

		$rootStructureSectionId = Invitation::getRootStructureSectionId();

		return [
			'INTRANET_INVITATION_CAN_INVITE' => $canInvite,
			'INTRANET_INVITATION_ROOT_STRUCTURE_SECTION_ID' => $rootStructureSectionId,
			'INTRANET_INVITATION_REGISTER_URL' => $registerUrl,
			'INTRANET_INVITATION_REGISTER_ADMIN_CONFIRM' => $registerAdminConfirm,
			'INTRANET_INVITATION_REGISTER_ADMIN_CONFIRM_DISABLE' => $disableRegisterAdminConfirm,
			'INTRANET_INVITATION_REGISTER_SHARING_MESSAGE' => $registerSharingMessage,
			'INTRANET_INVITATION_IS_ADMIN' => $isIntranetInvitationAdmin,
		];
	}

	private function isDevelopmentEnvironment(): bool
	{
		return Option::get('immobile', 'IS_DEVELOPMENT_ENVIRONMENT', 'N') === 'Y';
	}

	private function isNetworkSearchAvailable(): bool
	{
		return Loader::includeModule('imbot') && class_exists('\Bitrix\ImBot\Integration\Ui\EntitySelector\NetworkProvider');
	}

	private function isLinesOperator(): bool
	{
		if (!ModuleManager::isModuleInstalled('imopenlines'))
		{
			return false;
		}

		return Imopenlines\User::isOperator();
	}

	private function getCallServerMaxUsers(): int
	{
		if (Loader::includeModule('call'))
		{
			return \Bitrix\Call\Call::getMaxCallServerParticipants();
		}
		return 0;
	}

	private function getServiceHealthUrl(): string
	{
		$license = Application::getInstance()->getLicense();

		$baseUrl = $license->isCis()
			? 'https://status.bitrix24.ru/json_status.php?reg='
			: 'https://status.bitrix24.com/json_status.php?reg='
		;

		return $baseUrl . $license->getRegion();
	}

	private function getVideoCallsTermsUrl(): string
	{
		$license = Application::getInstance()->getLicense();

		return match ($license->getRegion()) {
			'ru' => 'https://www.bitrix24.ru/about/terms_of_use_videocalls.php',
			'kz' => 'https://www.bitrix24.kz/about/terms_of_use_videocalls.php',
			'by' => 'https://www.bitrix24.by/about/terms-of-use-videocalls.php',
			default => $license->isCis()
				? 'https://www.bitrix24.kz/about/terms_of_use_videocalls.php'
				: 'https://www.bitrix24.com/terms/terms-of-use-videocalls.php',
		};
	}

	private function getMaxTranscribableFileSize(): int
	{
		if (!Loader::includeModule('im'))
		{
			return 26214400;
		}

		return TranscribeManager::MAX_TRANSCRIBABLE_FILE_SIZE;
	}

	private function canUseAudioPanel(): bool
	{
		return Feature::isEnabled(AudioPlayerV2Flag::class);
	}

	private function getNavigationTabTitles(): array
	{
		$titles = [];
		foreach ($this->getSortedItems() as $tab)
		{
			if ($tab->isAvailable())
			{
				$titles[$tab->getId()] = $tab->getTabTitle();
			}
		}

		return $titles;
	}

	private function getAiAssistantStatusMessages(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		return StatusMessageProvider::get(Platform::MOBILE);
	}

	private function getCopilotSuggests(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		return SuggestProvider::getList();
	}

	private function getCopilotGreetings(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		return GreetingProvider::getList();
	}

	private function getCopilotGreetingWeekHolidays(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		return GreetingProvider::getWeekHolidays();
	}

	private function getCopilotData(): ?array
	{
		$copilotId = \Bitrix\Im\V2\Integration\AI\AIHelper::getCopilotBotId();
		if (!$copilotId)
		{
			return null;
		}

		return \Bitrix\Im\V2\Entity\User\User::getInstance($copilotId)->toRestFormat();
	}

	private function getAvailableEngines(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		$engineManager = new EngineManager();

		return $engineManager->getAvailableEnginesForRest();
	}

	private function getCopilotBotName(): string
	{
		if (!Loader::includeModule('im'))
		{
			return '';
		}

		return CopilotNameResolver::getInstance()->getName();
	}

	private function getCopilotAgentName(): string
	{
		if (!Loader::includeModule('im'))
		{
			return '';
		}

		return CopilotNameResolver::getInstance()->getAgentName();
	}
}
