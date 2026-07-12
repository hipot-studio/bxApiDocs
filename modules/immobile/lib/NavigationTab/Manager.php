<?php

namespace Bitrix\ImMobile\NavigationTab;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Folder\FolderCollection;
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
use Bitrix\ImMobile\Settings;
use Bitrix\ImMobile\User;
use Bitrix\Im\Integration\Imopenlines\Localize;
use Bitrix\Im\Integration\Imopenlines;
use Bitrix\Intranet\Invitation;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Im\V2\Integration\AI\EngineManager;

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
			array_filter($sortedTabs, static fn ($item) => $item->isWidgetAvailable()
				|| ($item->getId() === 'openlines' && $item->isAvailable())
			)
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
		try {
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

	private function getCacheId($additionalString = ''): string
	{
		if (Features::get()->isChatFoldersAvailable)
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
		if (!Features::get()->isChatFoldersAvailable)
		{
			return $this->getDefaultPresetByContext();
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
			if ($folderId === null)
			{
				continue;
			}

			$tab = $folder instanceof SystemFolder
				? $this->createSystemTab($folder->getCode(), $folder)
				: null;

			$tabs[] = $tab ?? new Tab\Folder($folder);
		}

		return $tabs;
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
			'default'     => new Tab\Messenger($folder),
			'tasksTask'   => new Tab\Task($folder),
			'copilot'     => new Tab\Copilot($folder),
			'openChannel' => new Tab\Channel($folder),
			'collab'      => new Tab\Collab($folder),
			'openlines'   => new Tab\OpenLines($this->context, $folder),
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
	 * @param Tab\TabInterface[] $tabs
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
	 * @param Tab\TabInterface[] $tabs
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function getSharedParams(array $tabs): array
	{
		$permissions = [];
		if (Loader::includeModule('im'))
		{
			$permissionManager = new \Bitrix\Im\V2\Permission(true);
			$permissions = [
				'byChatType' => $permissionManager->getByChatTypes(),
				'byUserType' => $permissionManager->getByUserTypes(),
				'actionGroups' => $permissionManager->getActionGroupDefinitions(),
				'actionGroupsDefaults' => $permissionManager->getDefaultPermissionForGroupActions(),
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

		$firstTabId = '';
		if (count($tabs) > 0)
		{
			$firstTabId = $tabs[0]->getId();
		}
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
			],
			'IS_CLOUD' => $isCloud,
			'HAS_ACTIVE_CLOUD_STORAGE_BUCKET' => $hasActiveBucket,
			'IS_BETA_AVAILABLE' => Settings::isBetaAvailable(),
			'IS_COPILOT_SELECT_MODEL_ENABLED' => Settings::isCopilotSelectModelEnabled(),
			'IS_CHAT_LOCAL_STORAGE_AVAILABLE' => Settings::isChatLocalStorageAvailable(),
			'IS_MARKDOWN_PARSER_ENABLED' => Settings::isMarkdownParserEnabled(),
			'IS_OPENLINES_IN_MESSENGER_V2_AVAILABLE' => Settings::isOpenlinesInMessengerV2Available(),
			'IS_RECENT_FILTER_AVAILABLE' => Settings::isRecentFilterAvailable(),
			'IS_EXTERNAL_CHAT_MESSAGE_FORWARDING_AVAILABLE' => Settings::isExternalChatMessageForwardingAvailable(),
			'IS_TASKS_RECENT_LIST_AVAILABLE' => Settings::isTasksRecentListAvailable(),
			'IS_MARKET_AVAILABLE' => Settings::isMarketAvailable(),
			'IS_AUTO_TASKS_ENABLED' => Settings::isAutoTaskEnabled(),
			'IS_AUTO_TASKS_UI_AVAILABLE' => Settings::isAutoTaskUIAvailable(),
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
			'PERMISSIONS' => $permissions,
			'MULTIPLE_ACTION_MESSAGE_LIMIT' => Settings::getMultipleActionMessageLimit(),
			'CALL_SERVER_MAX_USERS' => $this->getCallServerMaxUsers(),
			'SERVICE_HEALTH_URL' => $this->getServiceHealthUrl(),
			'AI_SETTINGS' => [
				'MAX_TRANSCRIBABLE_FILE_SIZE' => $this->getMaxTranscribableFileSize(),
			],
			'CAN_USE_AUDIO_PANEL' => $this->canUseAudioPanel(),
			'COPILOT_DATA' => $copilot,
			'COPILOT_AVAILABLE_ENGINES' => $this->getAvailableEngines(),
			'COPILOT_BOT_NAME' => $this->getCopilotBotName(),
		];
	}

	private function getFolderCollection(): FolderCollection
	{
		if ($this->folderCollectionCache !== null)
		{
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
		if (!Features::get()->isChatFoldersAvailable)
		{
			return [];
		}

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
		return Option::get('im', 'can_use_audio_panel', 'N') !== 'N';
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
}
