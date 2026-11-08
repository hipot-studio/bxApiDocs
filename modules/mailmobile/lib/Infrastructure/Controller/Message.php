<?php

namespace Bitrix\MailMobile\Infrastructure\Controller;

use Bitrix\Bitrix24;
use Bitrix\Mail\Helper;
use Bitrix\Bitrix24\MailCounter;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Mailbox\ProblemMailboxStateService;
use Bitrix\Mail\Helper\Mailbox\ProviderAccessRestriction;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Helper\MessageFolder;
use Bitrix\Mail\Helper\MailContact;
use Bitrix\Mail\Integration\Crm\CrmTemplateProvider;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\Mail\Helper\Message\Loader\FilterPreset;
use Bitrix\Mail\Helper\Message\Loader\MessageFilter;
use Bitrix\Mail\Integration\AI\SubjectAvailability;
use Bitrix\Mail\Integration\AI\SubjectGenerator;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Public\Service\Draft\Dto\DraftOwnedAttachment;
use Bitrix\Mail\Public\Service\Draft\Dto\DraftSendClaim;
use Bitrix\Mail\Public\Service\Draft\DraftSendService;
use Bitrix\Mail\Public\Service\Message\Conversation\ConversationFilter;
use Bitrix\Mail\Public\Service\Message\Conversation\ConversationListService;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationListRequest;
use Bitrix\Mail\Public\Service\LargeAttachment\GuideState as LargeAttachmentGuideState;
use Bitrix\Mail\Public\Service\Message\BodyDarkThemeService;
use Bitrix\Mail\Public\Service\RecipientLimitService;
use Bitrix\Mail\MailMessageTable;
use Bitrix\MailMobile\FileUploader\MailUploaderController;
use Bitrix\MailMobile\Internal\Integration\Disk\AttachmentPlacement;
use Bitrix\MailMobile\Internal\Integration\Disk\AttachmentPlacementFactory;
use Bitrix\MailMobile\Internal\Integration\Mail\AttachmentLimit;
use Bitrix\MailMobile\Internal\Integration\Mail\Label\LabelCatalog;
use Bitrix\MailMobile\Internal\Integration\Mail\LargeAttachment\ScenarioAvailability;
use Bitrix\MailMobile\Internal\Integration\Mail\LargeAttachment\SendPreparation;
use Bitrix\MailMobile\Internal\Integration\Mail\MigrationNoticeProvider;
use Bitrix\MailMobile\Internal\Services\InlineAttachment;
use Bitrix\MailMobile\Internal\Services\FavoriteMessageEnricher;
use Bitrix\MailMobile\Internal\Services\FavoriteMessageReadService;
use Bitrix\MailMobile\Internal\Services\LabelListMode;
use Bitrix\MailMobile\Internal\Services\MessageLoader;
use Bitrix\MailMobile\Internal\Services\MessageListContext;
use Bitrix\MailMobile\Public\Features\DeviceContactsFeature;
use Bitrix\MailMobile\Public\Features\FavoritesFeature;
use Bitrix\MailMobile\Public\Features\LargeAttachmentFeature;
use Bitrix\MailMobile\Public\Features\MailTemplatesFeature;
use Bitrix\MailMobile\Public\Features\SignaturesFeature;
use Bitrix\MailMobile\Public\Features\UserLabelsFeature;
use Bitrix\MailMobile\Public\Service\MailSenderService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Config\Feature as CoreFeature;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Mail\Internals\MailContactTable;
use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\FinderDestTable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Mail\Sender\Identity;
use Bitrix\Main\Mail\SenderSendCounter;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Config\Feature as MobileFeature;
use Bitrix\UI\FileUploader\Uploader;
use CFile;
use COption;
use Exception;
use ReflectionException;
use Bitrix\Intranet;

class Message extends Controller
{
	public const CRM_MESSAGE_TYPE = FavoriteMessageReadService::CRM_MESSAGE_TYPE;

	private const TAB_UNREAD = 'unread';
	private const TAB_SENT = 'sent';
	private const VIRTUAL_FOLDER_FAVORITES = 'favorites';
	private const CONVERSATION_PAGE_LIMIT = 50;
	private const CONVERSATION_INVALID_FILTER_ERROR = 'INVALID_FILTER';
	private const CONVERSATION_MAILBOX_UNAVAILABLE_ERROR = 'MAILBOX_UNAVAILABLE';
	private const CONVERSATION_COMPATIBLE_UNSUPPORTED_FILTERS = ['SENDER', 'RECIPIENT'];
	private const PROJECTION_NOT_READY_ERROR = 'PROJECTION_NOT_READY';

	private static function getEmptySubjectPlaceholder(): string
	{
		return (string)(Loc::getMessage('MAILMOBILE_MESSAGE_EMPTY_SUBJECT_PLACEHOLDER'));
	}

	private function getRecipientLimits(): array
	{
		if (class_exists(RecipientLimitService::class))
		{
			return RecipientLimitService::getRecipientLimits();
		}

		return $this->getCompatibleRecipientLimits();
	}

	/**
	 * Keeps mobile mail compatible with a mail module released before RecipientLimitService.
	 *
	 * @return array{total: int, perField: int}
	 */
	private function getCompatibleRecipientLimits(): array
	{
		$perFieldLimit = -1;

		if (
			Loader::includeModule('bitrix24')
			&& (!Bitrix24\Feature::isFeatureEnabled('mail_mailbox_sync') || \CBitrix24::IsDemoLicense())
		)
		{
			$perFieldLimit = 1;
		}

		return [
			'total' => 10,
			'perField' => $perFieldLimit,
		];
	}

	/**
	 * The device address book stays hidden until the portal turns the feature flag on.
	 */
	private function isDeviceContactsAvailable(): bool
	{
		return Loader::includeModule('mobile')
			&& class_exists(MobileFeature::class)
			&& MobileFeature::isEnabled(DeviceContactsFeature::class);
	}

	/**
	 * Signature management requires both the mobile rollout and the mail server capability.
	 */
	private function isSignaturesAvailable(): bool
	{
		return Loader::includeModule('mobile')
			&& class_exists(MobileFeature::class)
			&& MobileFeature::isEnabled(SignaturesFeature::class)
			&& method_exists(Helper\Config\Feature::class, 'isMobileSignaturesAvailable')
			&& Helper\Config\Feature::isMobileSignaturesAvailable();
	}

	/**
	 * Email templates stay hidden until the portal turns the mobile scenario on.
	 */
	protected function isMailTemplatesEnabled(): bool
	{
		return CoreFeature::isEnabled(MailTemplatesFeature::class);
	}

	/**
	 * Whether the large attachment scenario is rolled out for mobile mail.
	 */
	private function isLargeAttachmentEnabled(): bool
	{
		return Loader::includeModule('mobile')
			&& class_exists(MobileFeature::class)
			&& MobileFeature::isEnabled(LargeAttachmentFeature::class)
		;
	}

	/**
	 * Everything the compose form needs to know about the large attachment scenario. Every meaning here
	 * belongs to the mail module: the threshold, the tariff and the one-time guides. Mobile mail adds only
	 * its own rollout flag and passes the rest on unchanged, so the two platforms cannot drift apart.
	 *
	 * A mail module released before the scenario reports it as switched off instead of failing the preload.
	 *
	 * @return array{
	 *     largeAttachmentEnabled: bool,
	 *     largeAttachmentTariffAvailable: bool,
	 *     largeAttachmentMaxSize: int,
	 *     largeAttachmentShowAha: bool,
	 *     largeAttachmentAhaOptionName: string,
	 *     largeAttachmentShowStorageNotice: bool,
	 *     largeAttachmentStorageNoticeOptionName: string,
	 *     largeAttachmentPostSendPromptSuppressed: bool,
	 *     largeAttachmentPostSendPromptOptionName: string
	 * }
	 */
	private function getLargeAttachmentInfo(): array
	{
		$enabled = $this->isLargeAttachmentAvailable();
		$tariffAvailable = $enabled && $this->isLargeAttachmentTariffAvailable();
		$guideAvailable = class_exists(LargeAttachmentGuideState::class)
			&& method_exists(LargeAttachmentGuideState::class, 'getAhaOptionName')
		;

		return [
			'largeAttachmentEnabled' => $enabled,
			'largeAttachmentTariffAvailable' => $tariffAvailable,
			'largeAttachmentMaxSize' => $this->getMaxAttachedFilesSize(),
			'largeAttachmentShowAha' => $tariffAvailable
				&& $guideAvailable
				&& !LargeAttachmentGuideState::wasAhaShown(),
			'largeAttachmentAhaOptionName' => $guideAvailable
				? LargeAttachmentGuideState::getAhaOptionName()
				: '',
			'largeAttachmentShowStorageNotice' => $tariffAvailable
				&& !$this->wasLargeAttachmentStorageNoticeShown(),
			'largeAttachmentStorageNoticeOptionName' => LargeAttachmentFeature::USER_OPTION_STORAGE_NOTICE_NAME,
			'largeAttachmentPostSendPromptSuppressed' => $guideAvailable
				&& LargeAttachmentGuideState::wasPostSendPromptSuppressed(),
			'largeAttachmentPostSendPromptOptionName' => $guideAvailable
				? LargeAttachmentGuideState::getPostSendPromptOptionName()
				: '',
		];
	}

	/**
	 * Whether the whole large attachment scenario may be offered to the client. The mobile rollout flag alone
	 * is not enough: a mail module released before the scenario, or a portal without the disk upload option,
	 * cannot convert a set, and a placement that is not reachable (no disk or ui module) cannot store an
	 * attachment. Declaring the scenario on in any of these cases would send an over-limit letter to the
	 * tariff screen instead of the compatible plain send, and would leave conversion impossible altogether.
	 *
	 * The tariff stays a separate answer (largeAttachmentTariffAvailable): storing an attachment in the Disk
	 * costs nothing extra, only sending it as a link does. isLargeAttachmentSendAvailable checks the disk
	 * upload option, not the tariff.
	 */
	protected function isLargeAttachmentAvailable(): bool
	{
		return $this->isLargeAttachmentEnabled()
			&& $this->isLargeAttachmentSendAvailable()
			&& $this->isAttachmentPlacementAvailable()
		;
	}

	/**
	 * Both halves of the gate are read from ScenarioAvailability, so no composite of the scenario is
	 * assembled here a second time. The two are kept as methods of their own because a test switches the
	 * halves apart, and the rollout flag is asked above them for the same reason: a test that revokes the
	 * rollout while both halves answer yes must still see the scenario off.
	 */
	protected function isLargeAttachmentSendAvailable(): bool
	{
		return ScenarioAvailability::isSendAvailable();
	}

	protected function isAttachmentPlacementAvailable(): bool
	{
		return ScenarioAvailability::isPlacementAvailable();
	}

	/**
	 * The tariff has one home too, and it is the adapter of the large attachment layer of the mail module.
	 * The compose preload and the confirmation of a set read it from there, so the answer the form was given
	 * and the answer a set is confirmed with cannot disagree about a portal.
	 */
	protected function isLargeAttachmentTariffAvailable(): bool
	{
		return SendPreparation::isAllowedByTariff();
	}

	/**
	 * The size a message may spend on attachments before encoding. The same value the canonical guard of
	 * the mail module compares the set against, so the client threshold cannot disagree with the send.
	 */
	protected function getMaxAttachedFilesSize(): int
	{
		return AttachmentLimit::getMaxTotalSize();
	}

	/**
	 * The explanation about the space the Disk spends is a guide of mobile mail alone, but it is kept next to
	 * the guides the mail module owns, so that the two platforms do not explain the same thing twice.
	 */
	private function wasLargeAttachmentStorageNoticeShown(): bool
	{
		if (!class_exists(LargeAttachmentGuideState::class))
		{
			return false;
		}

		return \CUserOptions::GetOption(
			LargeAttachmentGuideState::getUserOptionCategory(),
			LargeAttachmentFeature::USER_OPTION_STORAGE_NOTICE_NAME,
			null,
		) === 'Y';
	}

	protected function getDefaultPreFilters(): array
	{
		return
			[
				new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, 'application/x-www-form-urlencoded']),
				new Main\Engine\ActionFilter\Authentication(),
				new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_GET, Main\Engine\ActionFilter\HttpMethod::METHOD_POST]
				),
				// The framework adds this one by itself only to a POST, so a GET would stay unsigned without it.
				new Main\Engine\ActionFilter\Csrf(),
				new Intranet\ActionFilter\IntranetUser(),
			];
	}

	/**
	 * Actions that change something are narrowed down to POST on top of the signed request: neither sending a
	 * message nor spending the AI quota of the portal may be triggered by a plain link.
	 */
	public function configureActions(): array
	{
		$post = [
			'+prefilters' => [
				new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			],
		];

		return [
			'sendMessage' => $post,
			'generateSubject' => $post,
		];
	}

	/**
	 * @throws LoaderException
	 */
	private function includeRequiredModules(): bool
	{
		$result = (Loader::includeModule('mobile')
			&& Loader::includeModule('mail')
			&& Loader::includeModule('intranet'))
		;

		if (!$result)
		{
			$this->addError(
				new Error(
					'Modules must be installed: mobile, mail, intranet', 'REQUIRED_MODULES_NOT_INSTALLED'
				)
			);
		}

		return $result;
	}

	/**
	 * Page of the mail grid: a plain page of messages or, when the conversation feature is on for
	 * the user, the page of conversations of the very same context.
	 *
	 * @param PageNavigation $navigation page and size of the requested block
	 * @param string|null $tabId active tab of the grid
	 * @param int|null $mailboxId requested mailbox, the previously seen one when empty
	 * @param string|null $presetId filter preset of the mailbox
	 * @param array $filterParams raw filter params of the grid
	 * @param string|null $virtualFolderKey virtual folder of the grid, if any
	 * @param string|null $cursor opaque cursor of the next conversation page
	 * @param array $sort sorting of the list: `field` and `direction`
	 * @param bool $supportsGroupedRows whether this client can draw conversation rows
	 * @return array
	 * @throws Main\LoaderException
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function getMessageListAction(
		PageNavigation $navigation,
		?string $tabId = null,
		?int $mailboxId = null,
		?string $presetId = null,
		array $filterParams = [],
		?string $virtualFolderKey = null,
		?string $cursor = null,
		array $sort = [],
		bool $supportsGroupedRows = true,
		?int $labelId = null,
	): array
	{
		$favoritesAvailable = $this->isFavoritesAvailable();
		$labelsAvailable = $this->isLabelsAvailable();
		if (!$this->includeRequiredModules())
		{
			return [
				'mailboxIsNotAvailable' => true,
				'favoritesAvailable' => $favoritesAvailable,
				'labelsAvailable' => $labelsAvailable,
			];
		}

		// MailAccess belongs to the mail module, so the flag is resolved after the module guard.
		$messageDeleteAvailable = $this->isMessageDeleteAvailable();

		$selectedDirPath = isset($filterParams['DIR']) && is_scalar($filterParams['DIR'])
			? (string)$filterParams['DIR']
			: null
		;
		// Read before the context is resolved: the resolver records a new previously seen mailbox,
		// while the onboarding right belongs to the mailbox seen on the preceding request.
		$hadPreviousMailbox = (int)\CUserOptions::getOption('mail', 'previous_seen_mailbox_id', 0) > 0;
		$context = $this->resolveMessageListContext(
			$mailboxId,
			$selectedDirPath,
			$virtualFolderKey,
			$tabId,
			$favoritesAvailable,
		);
		if (is_array($context))
		{
			return [
				...$context,
				'favoritesAvailable' => $favoritesAvailable,
				'labelsAvailable' => $labelsAvailable,
				'messageDeleteAvailable' => $messageDeleteAvailable,
			];
		}

		$mailboxHelper = $context->mailbox;
		$mailboxDirsHelper = $context->directories;
		$defaultDir = $context->defaultDirectory;
		$selectedDir = $context->selectedDirectory;
		$mailboxId = $context->mailboxId;
		$userId = $context->userId;
		$favoritesEnabled = $context->favoritesEnabled;
		$providerRestriction = $context->providerRestriction;
		$mailboxIdsForFilter = [$mailboxId];
		$isFavoritesMode = $favoritesEnabled && $virtualFolderKey === self::VIRTUAL_FOLDER_FAVORITES;
		// Favorites win: two virtual keys at once are impossible, so the label is asked about second.
		$currentLabel = $isFavoritesMode
			? null
			: $this->resolveCurrentLabel($userId, $mailboxId, $virtualFolderKey, $labelId, $labelsAvailable)
		;
		$isLabelMode = $currentLabel !== null;
		$presetFields = null;
		if ($isFavoritesMode && $presetId !== null)
		{
			$preset = FilterPreset::getFilterByPresetId($presetId, $mailboxId);
			if (is_array($preset['fields'] ?? null))
			{
				$presetFields = $preset['fields'];
			}
		}

		$favoritesFilterState = self::buildFavoritesFilterState(
			$mailboxIdsForFilter,
			$filterParams,
			$virtualFolderKey,
			$tabId,
			$userId,
			$favoritesEnabled,
			$presetFields,
			fn (): array => $context->accessibleMailboxIds,
			fn (): array => $context->excludedDirectoryHashes,
			fn (): array => $context->outcomeDirectoryHashes,
		);
		$mailboxIdsForFilter = $favoritesFilterState['mailboxIds'];
		$filterParams = $favoritesFilterState['filterParams'];
		$filter = $favoritesFilterState['filter'];

		if (!$isFavoritesMode && !$isLabelMode)
		{
			$mailboxIdsForFilter = $this->applyVirtualFolderFilter(
				$virtualFolderKey,
				$tabId,
				$mailboxIdsForFilter,
				$filterParams,
				$userId,
			);
		}
		// A label gathers messages of the whole mailbox, so neither the folder nor the sent tab applies.
		$currentDir = $isFavoritesMode || $isLabelMode
			? ($selectedDir ?? $defaultDir)
			: $this->applyTabFilter($tabId, $selectedDir ?? $defaultDir, $mailboxDirsHelper, $filterParams)
		;
		$currentDir = $currentDir ?? $defaultDir;
		$currentDirPath = $currentDir->getPath(true);

		if (!$isFavoritesMode)
		{
			// In all_messages mode MessageFilter picks MD5_DIRS over DIR and ignores this line.
			$filterParams['DIR'] = $currentDirPath;

			// A preset replaces the filter of the grid, so both paths are built from its fields.
			$filterParams = $this->applyFilterPreset($filterParams, $presetId, $mailboxId);
			if ($isLabelMode)
			{
				$filterParams = LabelListMode::prepareFilterParams($filterParams, (int)$currentLabel['id']);
			}
			$filter = new MessageFilter(
				$mailboxIdsForFilter,
				$filterParams,
				userId: $favoritesEnabled ? $userId : null,
			);
		}

		// Favorites select across mailboxes by a filter of their own, which the conversation
		// projection of the mail module does not describe, so that folder stays a plain list.
		$conversationResult = !$this->supportsConversationListForView($isFavoritesMode, $virtualFolderKey)
			? null
			: $this->requestConversationListPage(
				$mailboxId,
				$virtualFolderKey === null ? $this->resolveFilterDirPath($filterParams) : null,
				$virtualFolderKey,
				$filterParams,
				$sort,
				$this->resolveConversationLimit($navigation),
				$cursor,
				$supportsGroupedRows,
			)
		;

		if ($conversationResult !== null && !$conversationResult->isSuccess())
		{
			$this->addErrors($conversationResult->getErrors());

			return [];
		}

		$conversationPage = $conversationResult?->getData();
		$isGrouped = ($conversationPage['grouped'] ?? false) === true;

		$currentFolderPathForResponse = (
			$virtualFolderKey === MessageFolder::VIRTUAL_ALL_MESSAGES
			|| $isFavoritesMode
			|| $isLabelMode
		)
			? ''
			: $currentDirPath
		;
		$hideReadStatuses = self::resolveMessageListHideReadStatuses(
			$isFavoritesMode,
			$tabId,
			$currentDir->isOutcome(),
		);

		$legacyEnvelope = [
			'items' => $isGrouped
				? []
				: $this->loadMessageListForAction(
					$filter,
					$navigation,
					$hideReadStatuses,
					$favoritesAvailable,
				),
			'dirs' => $mailboxDirsHelper->buildDirectoryTreeForContextMenu($mailboxId, $mailboxHelper),
			'currentMailboxId' => $mailboxId,
			'currentFolderPath' => $currentFolderPathForResponse,
			'startEmailSender' => $mailboxHelper->getMailbox()['EMAIL'],
			'mailboxIsNotAvailable' => false,
			'providerRestriction' => $providerRestriction,
			'migrationNotice' => $this->resolveMigrationNotice($userId, $mailboxId),
			'messageCounterInAllMailboxes' => $context->unseenCounter,
			'favoritesAvailable' => $favoritesAvailable,
			'labelsAvailable' => $labelsAvailable,
			'currentLabel' => $currentLabel,
			'messageDeleteAvailable' => $messageDeleteAvailable,
		];

		return $this->applyConversationListPage($legacyEnvelope, $conversationPage, $hadPreviousMailbox);
	}

	protected function supportsConversationListForView(bool $isFavoritesMode, ?string $virtualFolderKey): bool
	{
		return !$isFavoritesMode
			&& $virtualFolderKey !== MessageFolder::VIRTUAL_ALL_MESSAGES
			&& $virtualFolderKey !== LabelListMode::VIRTUAL_FOLDER_LABEL
		;
	}

	/**
	 * DTO-03. The display decision for the message list surface, as the mail module answers it. A
	 * surface with nothing to show carries the decision "none" rather than no field at all, so the
	 * client tells the two apart by nothing.
	 *
	 * The decision travels with the letters: entering the list spends no request of its own.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveMigrationNotice(int $userId, int $mailboxId): array
	{
		return (new MigrationNoticeProvider())->forMessageList($userId, $mailboxId);
	}

	protected function isFavoritesAvailable(): bool
	{
		return CoreFeature::isEnabled(FavoritesFeature::class);
	}

	protected function isLabelsAvailable(): bool
	{
		return CoreFeature::isEnabled(UserLabelsFeature::class);
	}

	protected function isMessageDeleteAvailable(): bool
	{
		return MailAccess::hasUserAccessToMessageDeletion();
	}

	/**
	 * @return array{id: int, name: string, mailboxId: int, sort: int, unread: int}|null
	 */
	protected function resolveCurrentLabel(
		int $userId,
		int $mailboxId,
		?string $virtualFolderKey,
		?int $labelId,
		bool $labelsAvailable,
	): ?array
	{
		return LabelListMode::resolve(
			$userId,
			$mailboxId,
			$virtualFolderKey,
			$labelId,
			$labelsAvailable,
			static fn (int $userId, int $mailboxId): array => LabelCatalog::getForMailbox($userId, $mailboxId),
		);
	}

	private static function resolveMessageListHideReadStatuses(
		bool $isFavoritesMode,
		?string $tabId,
		bool $currentDirectoryIsOutcome,
	): bool
	{
		return $currentDirectoryIsOutcome || ($isFavoritesMode && $tabId === self::TAB_SENT);
	}

	protected function resolveMessageListContext(
		?int $mailboxId,
		?string $selectedDirPath,
		?string $virtualFolderKey,
		?string $tabId,
		bool $favoritesAvailable,
	): MessageListContext|array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		$previousMailboxId = (int)\CUserOptions::getOption('mail', 'previous_seen_mailbox_id', 0);
		$mailboxId = $mailboxId ?: $previousMailboxId;
		$mailbox = Mailbox::findBy($mailboxId);
		if ($mailbox !== null && $previousMailboxId !== $mailboxId)
		{
			\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
		}
		elseif ($previousMailboxId !== 0 && $mailbox === null)
		{
			$mailbox = Mailbox::findBy($previousMailboxId);
			$mailboxId = $previousMailboxId;
		}

		if ($mailbox === null)
		{
			foreach (MailboxTable::getUserMailboxes($userId, true) as $availableMailbox)
			{
				$mailboxId = (int)$availableMailbox['ID'];
				$mailbox = Mailbox::findBy($mailboxId);
				if ($mailbox !== null)
				{
					\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
					break;
				}
			}
		}

		if ($mailbox === null)
		{
			// The decision rides even here: a mailbox that fell off is exactly where it is most often due
			return [
				'mailboxIsNotAvailable' => true,
				'migrationNotice' => $this->resolveMigrationNotice($userId, 0),
			];
		}

		$providerRestriction = $this->resolveProviderRestriction($mailbox);
		$directories = $mailbox->getDirsHelper();
		$defaultDirectory = $directories->getDirByPath($directories->getDefaultDirPath(true));
		if (empty($defaultDirectory) || !$defaultDirectory->isSync())
		{
			return [
				'mailboxIsNotAvailable' => true,
				'currentMailboxId' => $mailbox->getMailboxId(),
				'providerRestriction' => $providerRestriction,
				'migrationNotice' => $this->resolveMigrationNotice($userId, $mailbox->getMailboxId()),
			];
		}

		$isFavoritesMode = $favoritesAvailable && $virtualFolderKey === self::VIRTUAL_FOLDER_FAVORITES;
		$accessibleMailboxIds = $isFavoritesMode
			? array_map('intval', array_keys(MailboxTable::getUserMailboxes($userId, true)))
			: []
		;
		[$excludedDirectoryHashes, $outcomeDirectoryHashes] = $isFavoritesMode
			? self::loadFavoriteDirectoryScopes($accessibleMailboxIds, $tabId)
			: [[], []]
		;
		global $USER;

		return new MessageListContext(
			$mailbox,
			$directories,
			$defaultDirectory,
			$directories->getDirByPath($selectedDirPath),
			$mailboxId,
			$userId,
			$favoritesAvailable,
			$accessibleMailboxIds,
			$excludedDirectoryHashes,
			$outcomeDirectoryHashes,
			$providerRestriction,
			(int)\CUserCounter::GetValue($USER->GetID(), 'mail_unseen'),
		);
	}

	private static function loadFavoriteDirectoryScopes(array $mailboxIds, ?string $tabId): array
	{
		if ($mailboxIds === [])
		{
			return [[], []];
		}

		$rows = MailboxDirectoryTable::getList([
			'select' => self::getFavoriteDirectorySelect($tabId),
			'filter' => ['@MAILBOX_ID' => $mailboxIds],
		])->fetchAll();

		return self::partitionFavoriteDirectoryScopes($rows, $tabId === self::TAB_SENT);
	}

	private static function getFavoriteDirectorySelect(?string $tabId): array
	{
		$select = ['MAILBOX_ID', 'DIR_MD5', 'IS_SPAM', 'IS_TRASH'];
		if ($tabId === self::TAB_SENT)
		{
			$select[] = 'IS_OUTCOME';
		}

		return $select;
	}

	private static function partitionFavoriteDirectoryScopes(array $rows, bool $includeOutcome): array
	{
		$excludedDirectoryHashes = [];
		$outcomeDirectoryHashes = [];
		foreach ($rows as $row)
		{
			$mailboxId = (int)($row['MAILBOX_ID'] ?? 0);
			$directoryHash = (string)($row['DIR_MD5'] ?? '');
			if ($mailboxId <= 0 || $directoryHash === '')
			{
				continue;
			}

			if (
				(int)($row['IS_SPAM'] ?? 0) === MailboxDirectoryTable::ACTIVE
				|| (int)($row['IS_TRASH'] ?? 0) === MailboxDirectoryTable::ACTIVE
			)
			{
				$excludedDirectoryHashes[$mailboxId][$directoryHash] = true;
			}
			if (
				$includeOutcome
				&& (int)($row['IS_OUTCOME'] ?? 0) === MailboxDirectoryTable::ACTIVE
			)
			{
				$outcomeDirectoryHashes[$mailboxId][$directoryHash] = true;
			}
		}

		return [
			array_map('array_keys', $excludedDirectoryHashes),
			array_map('array_keys', $outcomeDirectoryHashes),
		];
	}

	/**
	 * A preset of the mailbox replaces the filter of the grid whole, exactly as
	 * {@see MessageFilter::addPreset()} does it, so that both paths select the same messages.
	 */
	protected function applyFilterPreset(array $filterParams, ?string $presetId, int $mailboxId): array
	{
		$presetFields = $this->loadPresetFields($presetId, $mailboxId);

		return $presetFields === [] ? $filterParams : $presetFields;
	}

	protected function loadPresetFields(?string $presetId, int $mailboxId): array
	{
		if ($presetId === null || $presetId === '')
		{
			return [];
		}

		$presetFilter = FilterPreset::getFilterByPresetId($presetId, $mailboxId);

		return is_array($presetFilter['fields'] ?? null) ? $presetFilter['fields'] : [];
	}

	protected function resolveConversationLimit(PageNavigation $navigation): int
	{
		return $navigation->getLimit() > 0 ? $navigation->getLimit() : self::CONVERSATION_PAGE_LIMIT;
	}

	/**
	 * Folder the effective filter selects: a preset brings a folder of its own, and without one the
	 * current folder of the grid stays.
	 */
	protected function resolveFilterDirPath(array $filterParams): ?string
	{
		return isset($filterParams['DIR']) && is_scalar($filterParams['DIR'])
			? (string)$filterParams['DIR']
			: null
		;
	}

	/**
	 * Page of conversations for the current context, or null when this portal has no conversations
	 * to serve: the classes are not shipped yet, the service is not registered or grouped rows are
	 * unavailable for a known compatible reason. Access and malformed-request errors stay visible.
	 *
	 * @param array $filter raw filter params of the grid
	 * @param array $sort sorting of the list
	 * @param int $limit rows of one page
	 * @param string|null $cursor opaque cursor of the next page
	 * @param bool $supportsGroupedRows whether this client can draw conversation rows
	 */
	protected function requestConversationListPage(
		int $mailboxId,
		?string $folder,
		?string $virtualFolder,
		array $filter,
		array $sort,
		int $limit,
		?string $cursor,
		bool $supportsGroupedRows = true,
	): ?Main\Result
	{
		if (!class_exists(ConversationListService::class) || !class_exists(ConversationListRequest::class))
		{
			return null;
		}

		$service = $this->resolveConversationService(ConversationListService::class);
		if ($service === null)
		{
			return null;
		}

		$request = new ConversationListRequest(
			$mailboxId,
			$folder,
			$virtualFolder,
			$filter,
			$sort,
			$limit,
			$cursor,
		);
		$result = $service->getPage((int)($this->getCurrentUser()?->getId()), $request);

		if (!$result->isSuccess())
		{
			if (!$this->shouldFallbackToPlainList($result, $cursor, $filter))
			{
				return $result;
			}

			$page = $result->getData();
			if (is_array($page) && ($page['grouped'] ?? null) === false)
			{
				return (new Main\Result())->setData($page);
			}

			return null;
		}

		if (!$supportsGroupedRows)
		{
			$page = $result->getData();
			if (is_array($page))
			{
				$page['items'] = [];
				$page['grouped'] = false;
				$page['nextCursor'] = null;

				return (new Main\Result())->setData($page);
			}
		}

		return $result;
	}

	private function shouldFallbackToPlainList(Main\Result $result, ?string $cursor, array $filter): bool
	{
		if ($result->isSuccess())
		{
			return false;
		}

		$errors = $result->getErrors();
		$unsupportedFilterKeys = ConversationFilter::unsupportedKeys($filter);
		$isCompatibleFilter = $cursor === null
			&& $unsupportedFilterKeys !== []
			&& array_diff($unsupportedFilterKeys, self::CONVERSATION_COMPATIBLE_UNSUPPORTED_FILTERS) === []
		;

		return $errors !== [] && array_reduce(
			$errors,
			static fn(bool $compatible, Error $error): bool => $compatible && match ($error->getCode()) {
				self::PROJECTION_NOT_READY_ERROR,
				self::CONVERSATION_MAILBOX_UNAVAILABLE_ERROR => true,
				self::CONVERSATION_INVALID_FILTER_ERROR => $isCompatibleFilter,
				default => false,
			},
			true,
		);
	}

	/**
	 * The settings of the feature travel with every page, so that a client of the plain list knows
	 * whether grouping is available at all; the rows are replaced only by a grouped page.
	 */
	protected function applyConversationListPage(
		array $legacyEnvelope,
		?array $page,
		?bool $onboardingEligibilityCandidate = null,
	): array
	{
		if ($onboardingEligibilityCandidate !== null)
		{
			$legacyEnvelope['conversationOnboardingEligibilityCandidate'] = $onboardingEligibilityCandidate;
		}

		if ($page === null)
		{
			return $legacyEnvelope;
		}

		$legacyEnvelope['settings'] = $page['settings'] ?? [];
		$legacyEnvelope['grouped'] = ($page['grouped'] ?? false) === true;

		if (!$legacyEnvelope['grouped'])
		{
			return $legacyEnvelope;
		}

		$legacyEnvelope['items'] = $page['items'] ?? [];
		$legacyEnvelope['nextCursor'] = $page['nextCursor'] ?? null;

		return $legacyEnvelope;
	}

	protected function resolveConversationService(string $className): ?object
	{
		try
		{
			return ServiceLocator::getInstance()->get($className);
		}
		catch (ObjectNotFoundException | CircularDependencyException)
		{
			return null;
		}
	}

	protected function loadMessageListForAction(
		MessageFilter $filter,
		PageNavigation $navigation,
		bool $hideReadStatuses,
		bool $favoritesAvailable,
	): array
	{
		return MessageLoader::getMessageList($filter, $navigation, $hideReadStatuses, $favoritesAvailable);
	}


	/**
	 * Provider access restriction code for the mailbox or null.
	 * Non-null only when the mailbox is a problem one (cached status, no live check)
	 * AND its provider restricts third-party POP3/IMAP/SMTP access.
	 */
	private function resolveProviderRestriction(Mailbox $mailboxHelper): ?string
	{
		if (!(new ProblemMailboxStateService())->isProblemMailbox($mailboxHelper->getMailboxId()))
		{
			return null;
		}

		$serviceName = $mailboxHelper->getProviderCode();

		return (ProviderAccessRestriction::isFeatureEnabled() && ProviderAccessRestriction::isRestricted($serviceName))
			? ProviderAccessRestriction::resolveProviderName($serviceName)
			: null
		;
	}

	/**
	 * @param int $mailboxId
	 * @return array
	 * @throws ArgumentNullException
	 */
	public function getFilterPresetsAction(int $mailboxId = 0): array
	{
		if (!$mailboxId)
		{
			$mailboxId = (int)\CUserOptions::getOption('mail', 'previous_seen_mailbox_id', 0);
		}

		return [
			'presets' => FilterPreset::getAllPresets($mailboxId),
		];
	}

	/**
	 * Reports whether AI email-subject generation is available for the current
	 * portal in the given composition context. Thin wrapper over the availability
	 * adapter (which itself loads the required modules and checks the BitrixGPT
	 * switchers); the mobile client must not recompute the decision. Portal- and
	 * user-level flag, so no mailbox access check is needed here.
	 *
	 * @param bool $isCrm Whether the availability is queried for CRM composition.
	 * @return array{subjectGenerationAvailable: bool}
	 */
	public function getSubjectGenerationAvailableAction(bool $isCrm = false): array
	{
		return [
			'subjectGenerationAvailable' => $this->createSubjectAvailability()->isAvailable($isCrm),
		];
	}

	/**
	 * Generates a short e-mail subject from the body being composed. The AI module
	 * portal setting selects the requested mode, while the Engine facade determines
	 * the actual transport. The response contains either a queue hash or a subject
	 * that was produced immediately.
	 *
	 * The composition mode (regular or CRM) is determined by the server from the
	 * CRM entity the message is composed for, so which administrative BitrixGPT
	 * switcher applies is not a client decision.
	 *
	 * @param string $body Body being composed (primary source of the subject).
	 * @param string $mode One of "new" / "reply" / "forward".
	 * @param int|null $originalMessageId Saved original message id for reply/forward.
	 * @param string $currentSubject Current subject to improve; empty for generation from scratch.
	 * @param string $ownerType CRM entity type (id or symbolic name); empty for regular mail.
	 * @param int $ownerId CRM entity id; 0 for regular mail.
	 * @return array{queueHash?: string, subject?: string}
	 */
	public function generateSubjectAction(
		string $body,
		string $mode = SubjectGenerator::MODE_NEW,
		?int $originalMessageId = null,
		string $currentSubject = '',
		string $ownerType = '',
		int $ownerId = 0,
	): array
	{
		if (!$this->includeRequiredModules())
		{
			return [];
		}

		try
		{
			$result = $this->createSubjectGenerator()->generate(
				$body,
				$mode,
				$originalMessageId,
				$currentSubject,
				$ownerType,
				$ownerId,
			);
		}
		catch (\Throwable $e)
		{
			$this->writeToLogException($e);
			$this->addError(new Error('AI subject generation failed', SubjectGenerator::ERROR_GENERATION_FAILED));

			return [];
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	// Both AI services are stateless and built per call; the factories are the seam the tests replace.
	protected function createSubjectGenerator(): SubjectGenerator
	{
		return new SubjectGenerator();
	}

	protected function createSubjectAvailability(): SubjectAvailability
	{
		return new SubjectAvailability();
	}

	//todo: Move to Mail module
	/**
	 * @throws ArgumentException|SqlQueryException|SystemException|LoaderException|ObjectPropertyException|ReflectionException
	 */
	public function sendMessageAction(array $data): array
	{
		if (!$this->includeRequiredModules())
		{
			return [];
		}

		$hostname = $this->getHostname();

		$fromEmail = $data['from'];
		$fromAddress = new Address($fromEmail);

		if ($fromAddress->validate())
		{
			$fromEmail = $fromAddress->getEmail();

			\CBitrixComponent::includeComponentClass('bitrix:main.mail.confirm');
			if (!in_array($fromEmail, array_column(\MainMailConfirmComponent::prepareMailboxes(), 'email'), true))
			{
				$this->addError(new Error('Wrong sender email address'));

				return [];
			}

			if ($fromAddress->getName())
			{
				$fromEncoded = sprintf(
					'%s <%s>',
					sprintf('=?%s?B?%s?=', SITE_CHARSET, base64_encode($fromAddress->getName())),
					$fromEmail
				);
			}
		}
		else
		{
			$this->addError(new Error('Wrong sender email address'));

			return [];
		}

		$to = [];
		$cc = [];
		$bcc = [];
		$toEncoded = [];
		$ccEncoded = [];
		$bccEncoded = [];

		foreach (['to', 'cc', 'bcc'] as $field)
		{
			if (!empty($data[$field]) && is_array($data[$field]))
			{
				if (!isset(${$field}))
				{
					${$field} = [];
				}
				$fieldEncoded = $field.'Encoded';
				if (!isset(${$fieldEncoded}))
				{
					${$fieldEncoded} = [];
				}

				$addressList = [];
				foreach ($data[$field] as $item)
				{
					try
					{
						$item = Json::decode($item);

						$address = new Address();
						$address->setEmail($item['email']);
						$address->setName(htmlspecialcharsBack($item['name']));

						if ($address->validate())
						{
							if ($address->getName())
							{
								${$field}[] = $address->get();
								${$fieldEncoded}[] = $address->getEncoded();
							}
							else
							{
								${$field}[] = $address->getEmail();
								${$fieldEncoded}[] = $address->getEmail();
							}

							$addressList[] = $address;
						}
					}
					catch (Exception)
					{
					}
				}

				if (count($addressList) > 0)
				{
					$this->appendMailContacts($addressList, $field);
				}
			}
		}

		$to = array_unique($to);
		$cc = array_unique($cc);
		$bcc = array_unique($bcc);
		$toEncoded = array_unique($toEncoded);
		$ccEncoded = array_unique($ccEncoded);
		$bccEncoded = array_unique($bccEncoded);

		$recipientLimits = $this->getRecipientLimits();
		$emailsLimitToSendMessage = $recipientLimits['perField'];

		if ($emailsLimitToSendMessage !== -1 && (count($to) > $emailsLimitToSendMessage || count($cc) > $emailsLimitToSendMessage || count($bcc) > $emailsLimitToSendMessage))
		{
			$this->addError(new Error('Message tariff limit restriction'));

			return [];
		}

		if (count($to) + count($cc) + count($bcc) > $recipientLimits['total'])
		{
			$this->addError(new Error('To many recipients'));

			return [];
		}

		if (empty($to))
		{
			$this->addError(new Error('Empty recipients list'));

			return [];
		}

		$totalRecipientsCount = count($to) + count($cc) + count($bcc);
		$mailboxHelper = Mailbox::findBy($data['MAILBOX_ID'] ?? null, $fromEmail);

		if ($this->isSenderLimitReached(
			(string)$fromEmail,
			$totalRecipientsCount,
			$mailboxHelper?->getMailboxId() ?? 0,
		))
		{
			$this->addError(new Error('Sender daily limit reached'));

			return [];
		}

		if ($this->isDailyPortalLimitReached($totalRecipientsCount))
		{
			$this->addError(new Error('Client daily portal limit reached'));

			return [];
		}

		if ($this->isMonthPortalLimitReached($totalRecipientsCount))
		{
			$this->addError(new Error('Client month portal limit reached'));

			return [];
		}

		$messageBody = (string)$data['message'];
		$messageBodyHtml = '';
		if (!empty($messageBody))
		{
			if (!empty($data['htmlMessage']) && is_string($data['htmlMessage']))
			{
				$messageBody = Helper\Message::sanitizeHtml((string)$data['htmlMessage']);
				$messageBodyHtml = $messageBody;
			}
			else
			{
				$messageBodyHtml = Helper\Message::sanitizeHtml($messageBody);

				$messageBody = $this->buildPlainMessageHtml($messageBodyHtml);
				$messageBodyHtml = $messageBody;
			}

			$messageBody = str_replace(['http://bxacid:', 'https://bxacid:'], 'bxacid:', $messageBody);
		}

		$outgoingBody = $messageBody;
		$outgoingSubject = Helper\Message::getOutgoingSubject(
			(string)($data['subject'] ?? ''),
			$messageBodyHtml,
			self::getEmptySubjectPlaceholder(),
		);

		$attachments = [];
		$templateAttachmentFileIds = [];
		$templateObjectIdsByFileId = [];
		$fileTokens = isset($data['fileTokens']) && is_array($data['fileTokens']) ? $data['fileTokens'] : [];
		$userId = (int)($this->getCurrentUser()?->getId());
		$draftAttachmentIds = $this->getDraftAttachmentIds(
			$userId,
			$data['draftId'] ?? null,
			$data['draftRevision'] ?? null,
			$data['draftClientId'] ?? null,
		);

		$draftSetSourceObjectIds = $this->getDraftLargeAttachmentSourceIds($userId, $data);

		// The sets are confirmed before the attachments are assembled: a confirmed file must reach neither
		// the message nor the total size it is measured by.
		$largeAttachmentResult = $this->prepareLargeAttachments(
			$userId,
			$data,
			$fileTokens,
			$draftAttachmentIds,
			$draftSetSourceObjectIds,
			$outgoingBody,
		);
		if (!$largeAttachmentResult->isSuccess())
		{
			$this->addErrors($largeAttachmentResult->getErrors());

			return [];
		}

		$placement = $this->createAttachmentPlacement();
		$attachmentReader = $this->createAttachmentPlacementReader();

		$largeAttachmentData = $largeAttachmentResult->getData();
		$externalLinkIds = (array)($largeAttachmentData[SendPreparation::EXTERNAL_LINK_IDS] ?? []);
		// A confirmed set names its files by their Disk objects. The autosave stores its copy of a converted
		// file under the b_file of the source, so the copy is recognised by the stored file behind the object
		// as well. The two live in separate id spaces the exclusion must not mix, so both are passed apart.
		$confirmedObjectIds = (array)($largeAttachmentData[SendPreparation::CONFIRMED_FILE_IDS] ?? []);
		$confirmedStoredFileIds = $this->resolveStoredFileIds($placement, $confirmedObjectIds, $userId);
		// The copies the claimed draft holds for the confirmed sets leave the message as well, and they are
		// bound to their set by the data of the draft: a source object deleted after the autosave has no
		// stored file left to recognise its copy by.
		$draftCopyObjectIds = $this->resolveDraftCopiesOfConfirmedSets(
			$draftAttachmentIds,
			$confirmedObjectIds,
			$draftSetSourceObjectIds,
		);
		if ($draftCopyObjectIds === null)
		{
			// A confirmed set of the draft names a source object no copy of the claim answers for. Sending
			// the copy raw is what the set was made to avoid: a heavy one fails the send by its own size.
			$this->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_MESSAGE_DRAFT_ATTACHMENT_UNRESOLVED'),
				SendPreparation::ERROR_INVALID_SEND_CONTRACT,
			));

			return [];
		}
		$confirmedObjectIds = array_merge($confirmedObjectIds, $draftCopyObjectIds);
		[$fileTokens, $draftAttachmentIds] = $this->excludeConfirmedAttachments(
			$fileTokens,
			$draftAttachmentIds,
			$confirmedObjectIds,
			$confirmedStoredFileIds,
		);

		$draftSourceFileIds = array_map(
			static fn(array $ids): int => $ids['sourceFileId'],
			$draftAttachmentIds,
		);
		$draftObjectIds = array_map(
			static fn(array $ids): int => $ids['objectId'],
			$draftAttachmentIds,
		);
		$draftFileIds = array_keys($draftAttachmentIds);
		$repliedId = (int)($data['REPLIED_ID'] ?? 0);
		$hasAccessToMessage = $repliedId && $this->hasUserAccessToMessage($repliedId);
		if ($repliedId && !$hasAccessToMessage)
		{
			$this->addError(new Error('No access to replied message'));

			return [];
		}
		if (!empty($fileTokens) || $draftFileIds !== [])
		{
			$totalSize = 0;

			// The objects of the Disk the form reported. An attachment of mobile mail is stored in the Disk,
			// so the file is taken from there instead of an upload session that expires with the request.
			$diskObjectIds = [];
			foreach ($fileTokens as $token)
			{
				$objectId = AttachmentPlacement::parseObjectReference($token);
				if ($objectId !== null)
				{
					$diskObjectIds[] = $objectId;
				}
			}

			if ($diskObjectIds !== [] && $attachmentReader === null)
			{
				// The form reported objects of the Disk, yet the Disk is not reachable at all, so they
				// can be resolved into nothing. Going on would drop them and report a sent message
				// without the files they stand for. Fail closed before anything is created.
				//
				// A rollback of the rollout is not this case: an identifier a form issued before it
				// names a file that exists and the sender may read, so it keeps being resolved. What the
				// rollback revokes is a signed set, and that is refused by the confirmation of the sets.
				$this->addError(new Error(
					(string)Loc::getMessage('MAILMOBILE_MESSAGE_DISK_ATTACHMENT_UNAVAILABLE'),
					'MAILMOBILE_DISK_ATTACHMENT_UNAVAILABLE',
				));

				return [];
			}

			$currentFilesIds = array_values(
				array_unique(
					array_filter(
						array_map(static function($item) {
							if (is_numeric($item))
							{
								return (int) $item;
							}

							return null;
						}, $fileTokens)
					)
				)
			);

			// The draft already carries the replied attachments it was saved with - by the copy it made or by
			// the file that copy was made from - so a file it holds is not read out of the quote again and
			// attached a second time.
			$currentFilesIds = array_values(
				array_diff($currentFilesIds, $draftFileIds, array_values($draftSourceFileIds))
			);

			$pendingFilesIds = array_values(
				array_unique(
					array_filter(
						array_map(static function($item) {
							if (
								!is_numeric($item)
								&& is_string($item)
								&& AttachmentPlacement::parseObjectReference($item) === null
							)
							{
								return $item;
							}
							return null;
						}, $fileTokens)
					)
				)
			);

			$uploader = new Uploader(new MailUploaderController());
			$newCurrentFilesIds = $draftFileIds;
			$sourceFileIds = $draftSourceFileIds;
			$placedObjectIds = [];
			// The real name by stored file id, for every attachment whose stored file is not named after
			// it. An attachment of a received message is such a file: both paths that write one keep the
			// md5 of the name in b_file, so the name has to be carried here from the source that owns it.
			$attachmentNames = [];

			$resolvedDiskFiles = [];

			if ($attachmentReader !== null && $diskObjectIds !== [])
			{
				// The draft already owns copies of the files it was saved with, so an object whose file the
				// draft carries is not attached a second time - the same rule the branch of the quote obeys
				// through the difference above.
				$carriedByDraft = array_fill_keys(
					array_filter(array_merge($draftFileIds, array_values($draftSourceFileIds))),
					true,
				);

				$resolvedDiskFiles = $attachmentReader->resolveAttachmentFiles($diskObjectIds, $userId);
				if (count($resolvedDiskFiles) !== count(array_unique($diskObjectIds)))
				{
					// A selected object is gone or the user may not read it. Going on would drop it silently
					// and report a sent message without the file the person chose to attach.
					$this->addError(new Error(
						(string)Loc::getMessage('MAILMOBILE_MESSAGE_DISK_ATTACHMENT_UNAVAILABLE'),
						'MAILMOBILE_DISK_ATTACHMENT_UNAVAILABLE',
					));

					return [];
				}

				foreach ($resolvedDiskFiles as $objectId => $resolved)
				{
					$fileId = $resolved['fileId'];
					if (isset($carriedByDraft[$fileId]))
					{
						continue;
					}

					$newCurrentFilesIds[] = $fileId;
					$placedObjectIds[$fileId] = $objectId;
				}
			}

			// The stored file of an attachment of a received message is named after the md5 of its name, so
			// the object is the only place the real one is left. Naming is a question of its own: the loop
			// above skips a file the draft already carries, and the name of that file would be skipped
			// together with it.
			$attachmentNames += $this->resolveAttachmentNamesFromObjects(
				$resolvedDiskFiles,
				$draftAttachmentIds,
			);

			// A copy of the draft the form reported no object of - neither its own nor the one it was made
			// of - still leaves with the message, and its name is only on its object. One batch for all of
			// them, and only while at least one name is really missing.
			$unnamedDraftObjectIds = [];
			foreach ($draftAttachmentIds as $copyFileId => $ids)
			{
				$copyObjectId = (int)($ids['objectId'] ?? 0);
				if ($copyObjectId > 0 && !isset($attachmentNames[(int)$copyFileId]))
				{
					$unnamedDraftObjectIds[] = $copyObjectId;
				}
			}

			if ($attachmentReader !== null && $unnamedDraftObjectIds !== [])
			{
				$attachmentNames += $this->resolveAttachmentNamesFromObjects(
					$attachmentReader->resolveAttachmentFiles($unnamedDraftObjectIds, $userId),
					$draftAttachmentIds,
				);
			}

			if (!empty($currentFilesIds) && $hasAccessToMessage)
			{
				$repliedAttachments = MailMessageAttachmentTable::getList([
					'select' => [
						'ID',
						'FILE_ID',
						'FILE_NAME',
						'FILE_SIZE',
						'CONTENT_TYPE',
						'IMAGE_WIDTH',
						'IMAGE_HEIGHT',
					],
					'filter' => [
						'=MESSAGE_ID' => $repliedId,
						'@FILE_ID' => $currentFilesIds,
					],
				])->fetchAll();

				// The attachment of a quote leaves with the message from the file of the message it was read
				// from, exactly the way the web attaches it (mail.client/ajax.php): no copy of it is made
				// anywhere. A copy in the Disk of the user was dropped only when the send failed, so every
				// delivered reply left one behind forever and it kept eating the quota; the copy the branch
				// without the Disk made in b_file was never dropped at all.
				//
				// Nothing needs a copy. The conversion of a set asks the source one question - whether the
				// sender may read it (Mail\Integration\Disk\RealLargeAttachmentStorage::uploadAndLink) -
				// and the security context of the mail storage answers it by the access to the message,
				// not by who created the file. The copy into the folder of the set is a row of b_file over
				// the same physical file (File::copyTo, CFile::CloneFile), not a second copy of the bytes.
				foreach ($repliedAttachments as $item)
				{
					$repliedFileId = (int)$item['FILE_ID'];
					if ($repliedFileId <= 0)
					{
						continue;
					}

					$newCurrentFilesIds[] = $repliedFileId;
					$attachmentNames[$repliedFileId] = (string)$item['FILE_NAME'];
				}
			}

			$newCurrentFilesIds = array_values(array_unique($newCurrentFilesIds));

			// The Disk object an attachment is known by: an inline image of a restored draft points at the
			// object of the draft copy, and an attachment stored in the Disk at its own object.
			$draftObjectIds += $placedObjectIds;
			$templateObjectIdsByFileId = $draftObjectIds;

			$requiredFileIds = $this->collectAttachmentsThatMayNotBeDropped(
				$draftFileIds,
				$placedObjectIds,
			);

			foreach ($newCurrentFilesIds as $fileId)
			{
				$file = CFile::MakeFileArray($fileId);

				if (!$this->isStoredFileReadable($file))
				{
					if (isset($requiredFileIds[(int)$fileId]))
					{
						$this->addError(new Error(
							(string)Loc::getMessage('MAILMOBILE_MESSAGE_DISK_ATTACHMENT_UNAVAILABLE'),
							'MAILMOBILE_DISK_ATTACHMENT_UNAVAILABLE',
						));

						return [];
					}

					continue;
				}

				$fileSize = $file['size'] ?? 0;
				$totalSize += $fileSize;
				$contentId = sprintf(
					'bxacid.%s@%s.mail',
					hash('crc32b', ($file['external_id'] ?? $fileId) . $fileSize . $file['name']),
					hash('crc32b', $hostname)
				);

				$attachments[] = [
					'ID' => $contentId,
					'NAME' => $this->resolveOutgoingAttachmentName($file, $fileId, $attachmentNames),
					'PATH' => $file['tmp_name'],
					'CONTENT_TYPE' => $file['type'],
				];
				$templateAttachmentFileIds[] = (int)$fileId;

				// The body of a restored draft still points at the file its copy was made from, and an inline
				// image of it at the Disk object of that copy. A quote owns no copy at all, so there its own
				// file is the only reference the body can carry - which is what the fallback stands for.
				$outgoingBody = InlineAttachment::pointToContentId($outgoingBody, $contentId, [
					$fileId,
					$sourceFileIds[$fileId] ?? $fileId,
					$draftObjectIds[$fileId] ?? 0,
				]);
			}

			$pendingFiles = $uploader->getPendingFiles($pendingFilesIds);
			$draftPendingFileIds = array_fill_keys(array_filter(array_values($draftSourceFileIds)), true);

			foreach ($pendingFiles as $pendingFile)
			{
				if (!$pendingFile->isValid())
				{
					continue;
				}

				if (isset($draftPendingFileIds[$pendingFile->getFileId()]))
				{
					// The last autosave of the compose form already copied this upload into the draft, and
					// that copy is attached above. The pending file itself expires with its upload session.
					continue;
				}

				$fileId = $pendingFile->getFileId();
				$file = CFile::MakeFileArray($fileId);

				if (!$file || empty($file['tmp_name']))
				{
					$pendingFile->remove();

					continue;
				}

				$fileSize = $file['size'] ?? 0;
				if ($fileSize <= 0)
				{
					$pendingFile->remove();

					continue;
				}

				$totalSize += $fileSize;

				$contentId = sprintf(
					'bxacid.%s@%s.mail',
					hash('crc32b', ($file['external_id'] ?? $fileId) . $fileSize . $file['name']),
					hash('crc32b', $hostname)
				);

				$attachments[] = [
					'ID' => $contentId,
					'NAME' => $file['name'],
					'PATH' => $file['tmp_name'],
					'CONTENT_TYPE' => $file['type'],
				];
				$templateAttachmentFileIds[] = (int)$fileId;

				$outgoingBody = InlineAttachment::pointToContentId($outgoingBody, $contentId, [$fileId]);

				$pendingFile->makePersistent();
			}

			if (SendPreparation::exceedsMailLimit($totalSize))
			{
				$this->addError(new Error(
					(string)Loc::getMessage(
						'MAILMOBILE_MESSAGE_MAX_SIZE_EXCEED',
						['#SIZE#' => \CFile::formatSize(AttachmentLimit::getMaxTotalSizeAfterEncoding(), 1)],
					),
				));

				return [];
			}
		}

		if ($mailboxHelper && !$mailboxHelper->isAuthenticated())
		{
			$this->addError(new Error('Mailbox authentication error'));

			return [];
		}

		if ($repliedId > 0)
		{
			$repliedMessage = MailMessageTable::getRow([
				'runtime' => [
					new \Bitrix\Main\Entity\ReferenceField(
						'MESSAGE_UID',
						\Bitrix\Mail\MailMessageUidTable::class,
						[
							'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
							'=this.ID' => 'ref.MESSAGE_ID',
						],
						['join_type' => 'INNER'],
					),
				],
				'select' => [
					'FIELD_FROM',
					'FIELD_REPLY_TO',
					'FIELD_TO',
					'FIELD_CC',
					'FIELD_BCC',
					'MSG_ID',
					'HEADER',
					'BODY',
					'BODY_HTML',
					'SUBJECT',
					'FIELD_DATE',
					'INTERNALDATE' => 'MESSAGE_UID.INTERNALDATE',
				],
				'filter' => [
					'ID' => $data['REPLIED_ID'],
				],
			]);

			if (!empty($repliedMessage) && is_array($repliedMessage))
			{
				\Bitrix\Mail\Helper\Message::prepare($repliedMessage);

				$messageSanitized = true;

				if (trim($repliedMessage['BODY_HTML']))
				{
					$messageHtml = (new \Bitrix\Mail\Helper\Cache\SanitizedBodyCache())->get($repliedId);
					if (!$messageHtml)
					{
						$messageHtml = $repliedMessage['BODY_HTML'];
						$messageSanitized = false;
					}

				}
				else
				{

					$messageHtml = preg_replace('/(\s*(\r\n|\n|\r))+/', '<br>', htmlspecialcharsbx($repliedMessage['BODY']));
				}

				$repliedMessageQuote = \Bitrix\Mail\Message::wrapTheMessageWithAQuote(
					$messageHtml,
					$repliedMessage['SUBJECT'] ?? '',
					$repliedMessage['INTERNALDATE'] ?? $repliedMessage['FIELD_DATE'] ?? null,
					$repliedMessage['__from'],
					$repliedMessage['__to'],
					$repliedMessage['__cc'],
					$messageSanitized,
				);

				if (!empty($repliedMessageQuote))
				{

					$outgoingBody .= $repliedMessageQuote;
				}
			}
		}

		$outgoingParams = [
			'CHARSET' => SITE_CHARSET,
			'CONTENT_TYPE' => 'html',
			'ATTACHMENT' => $attachments,
			'TO' => implode(', ', $toEncoded),
			'SUBJECT' => $outgoingSubject,
			'BODY' => $outgoingBody,
			'HEADER' => [
				'From' => $fromEncoded ?: $fromEmail,
				'Reply-To' => $fromEncoded ?: $fromEmail,
				'Cc' => implode(', ', $ccEncoded),
				'Bcc' => implode(', ', $bccEncoded),
			],
		];

		if (isset($repliedMessage['MSG_ID']) && (int)$data['IS_FORWARD'] !== 1)
		{
			$outgoingParams['HEADER']['In-Reply-To'] = sprintf('<%s>', $repliedMessage['MSG_ID']);
		}

		$messageId = $this->generateMessageId($hostname);
		$outgoingParams['HEADER']['Message-Id'] = $messageId;

		try
		{
			if ($mailboxHelper === null)
			{
				$context = new Main\Mail\Context();
				$context->setCategory(Main\Mail\Context::CAT_EXTERNAL);
				$context->setPriority(
					count($to) + count($cc) + count($bcc) > 2
						? Main\Mail\Context::PRIORITY_LOW
						: Main\Mail\Context::PRIORITY_NORMAL
				);

				$sendResult = Main\Mail\Mail::send(array_merge(
					$outgoingParams,
					[ 'CONTEXT' => $context ],
				));
			}
			else
			{
				// Only this branch stores a local copy of the outgoing message, so only here is there a message
				// for the service marker to belong to. A direct send has no marker, inherited from the web.
				$markerPreparation = $this->createLargeAttachmentSendPreparation();
				$markerEventKey = $markerPreparation->addMarkerHandler($messageId, $externalLinkIds);

				try
				{
					$mailboxHelper->mail(array_merge(
						$outgoingParams,
						[
							'HEADER' => array_merge(
								$outgoingParams['HEADER'],
								[
									'To' => $outgoingParams['TO'],
									'Subject' => $outgoingParams['SUBJECT'],
								]
							),
						]
					));
				}
				finally
				{
					$markerPreparation->removeMarkerHandler($markerEventKey);
				}

				$sendResult = true;
			}
		}
		catch (\Throwable $sendError)
		{
			$this->writeToLogException($sendError);
			$this->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_MESSAGE_SENDING_FAILED'),
				'MAILMOBILE_MESSAGE_SEND_FAILED',
			));

			return [];
		}

		if ($sendResult !== true)
		{
			$this->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_MESSAGE_SENDING_FAILED'),
				'MAILMOBILE_MESSAGE_SEND_FAILED',
			));

			return [];
		}

		$templateSaveWarning = $this->saveMessageAsTemplateAfterSend(
			transportResult: $sendResult,
			saveRequested: $this->isTemplateSaveRequested($data),
			userId: $userId,
			subject: $outgoingSubject,
			bodyHtml: $messageBodyHtml,
			emailFrom: $fromEmail,
			diskObjectIds: $this->collectTemplateDiskObjectIds(
				$templateAttachmentFileIds,
				$templateObjectIdsByFileId,
			),
		);

		if (self::isDraftApiAvailable())
		{
			DraftSendService::completeAfterSuccessfulSend(DraftSendClaim::forMail(
				$userId,
				$data['draftId'] ?? null,
				$data['draftRevision'] ?? null,
				$data['draftClientId'] ?? null,
			));
		}

		addEventToStatFile(
			'mail',
			((empty($repliedMessage['MSG_ID']) || (int)$data['IS_FORWARD'] === 1) ? 'send_message' : 'send_reply'),
			'',
			trim(trim($messageId), '<>')
		);

		return [
			'MESSAGE_ID' => $messageId,
			'templateSaveWarning' => $templateSaveWarning,
		];
	}

	protected function buildPlainMessageHtml(string $messageBody): string
	{
		return sprintf(
			'<html><body>%s</body></html>',
			preg_replace('/[\r\n]+/', '<br>', htmlspecialcharsbx($messageBody)),
		);
	}

	protected function isTemplateSaveRequested(array $data): bool
	{
		return $this->isMailTemplatesEnabled()
			&& in_array($data['saveAsTemplate'] ?? null, [true, 1, '1'], true)
		;
	}

	/**
	 * @param list<int> $attachmentFileIds
	 * @param array<int, int> $objectIdsByFileId
	 * @return list<int>|null
	 */
	protected function collectTemplateDiskObjectIds(
		array $attachmentFileIds,
		array $objectIdsByFileId,
	): ?array
	{
		$diskObjectIds = [];
		foreach (array_unique($attachmentFileIds) as $fileId)
		{
			$objectId = (int)($objectIdsByFileId[$fileId] ?? 0);
			if ($objectId <= 0)
			{
				return null;
			}

			$diskObjectIds[] = $objectId;
		}

		return array_values(array_unique($diskObjectIds));
	}

	/**
	 * @param list<int>|null $diskObjectIds
	 */
	protected function saveMessageAsTemplateAfterSend(
		mixed $transportResult,
		bool $saveRequested,
		int $userId,
		string $subject,
		string $bodyHtml,
		string $emailFrom,
		?array $diskObjectIds,
	): bool
	{
		if ($transportResult !== true || !$saveRequested)
		{
			return false;
		}

		if ($diskObjectIds === null)
		{
			$this->logTemplateSaveFailure($userId, ['attachment object is unavailable']);

			return true;
		}

		$copiedObjectIds = [];
		try
		{
			$provider = $this->createCrmTemplateProvider();
			if (!$provider->canCreatePersonal($userId))
			{
				$this->logTemplateSaveFailure($userId, ['provider unavailable']);

				return true;
			}

			$attachmentCopies = $this->copyTemplateDiskObjects($diskObjectIds, $userId);
			if ($attachmentCopies === null)
			{
				$this->logTemplateSaveFailure($userId, ['attachment copy failed']);

				return true;
			}

			$copiedObjectIds = $attachmentCopies['diskObjectIds'];
			$bodyHtml = $this->replaceTemplateAttachmentReferences(
				$bodyHtml,
				$attachmentCopies['objectIdMap'],
			);

			$result = $provider->createPersonal(
				$subject,
				$bodyHtml,
				$emailFrom,
				$copiedObjectIds,
				$userId,
			);
			if ($result->isSuccess())
			{
				return false;
			}

			$this->removeTemplateDiskObjectCopies($copiedObjectIds);

			$this->logTemplateSaveFailure(
				$userId,
				array_map(
					static fn(Error $error): string => (string)($error->getCode() ?: $error->getMessage()),
					$result->getErrors(),
				),
			);
		}
		catch (\Throwable $exception)
		{
			$this->removeTemplateDiskObjectCopies($copiedObjectIds);
			$this->logTemplateSaveFailure($userId, [$exception->getMessage()]);
		}

		return true;
	}

	/**
	 * The template owns copies with independent b_file rows. A completed draft removes every Disk object
	 * backed by its attachment file id, so merely adding another Disk object over the same file would still
	 * make the template lose the attachment during draft cleanup. The identifiers arrive here only after the
	 * send path has resolved them through the user's Disk access or the claimed draft.
	 *
	 * @param list<int> $diskObjectIds
	 * @return array{diskObjectIds: list<int>, objectIdMap: array<int, int>}|null
	 */
	protected function copyTemplateDiskObjects(array $diskObjectIds, int $userId): ?array
	{
		if ($diskObjectIds === [])
		{
			return [
				'diskObjectIds' => [],
				'objectIdMap' => [],
			];
		}

		return $this->createAttachmentPlacementReader()?->copyForTemplate($diskObjectIds, $userId);
	}

	/** @param array<int, int> $objectIdMap */
	protected function replaceTemplateAttachmentReferences(string $bodyHtml, array $objectIdMap): string
	{
		foreach ($objectIdMap as $sourceObjectId => $copiedObjectId)
		{
			$bodyHtml = (string)preg_replace(
				'/bxacid:n?' . preg_quote((string)$sourceObjectId, '/') . '(?!\d)/i',
				'bxacid:n' . $copiedObjectId,
				$bodyHtml,
			);
		}

		return $bodyHtml;
	}

	/** @param list<int> $diskObjectIds */
	protected function removeTemplateDiskObjectCopies(array $diskObjectIds): void
	{
		$this->createAttachmentPlacementReader()?->removeTemplateCopies($diskObjectIds);
	}

	protected function createCrmTemplateProvider(): CrmTemplateProvider
	{
		return new CrmTemplateProvider();
	}

	/**
	 * @param list<string> $errors
	 */
	protected function logTemplateSaveFailure(int $userId, array $errors): void
	{
		try
		{
			(new Main\Diag\LoggerFactory())
				->createById('mail.Template')
				?->warning(
					'Template creation failed after successful mobile mail send.',
					[
						'userId' => $userId,
						'errors' => $errors,
					],
				)
			;
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * @throws ObjectPropertyException|ArgumentException|SystemException|LoaderException
	 */
	public function getUserContactsAction(): array
	{
		if (!$this->includeRequiredModules())
		{
			return [];
		}

		$mailSenderService = new MailSenderService();
		$senders = $mailSenderService->getSenderList();
		$mailTemplatesEnabled = $this->isMailTemplatesEnabled();

		return [
			'senders' => $senders,
			'mailTemplatesEnabled' => $mailTemplatesEnabled,
			'mailTemplateSaveAvailable' => $mailTemplatesEnabled && $this->isMailTemplateSaveAvailable(),
			'mailTemplateManagePath' => $mailTemplatesEnabled ? $this->resolveMailTemplateManagePath() : '',
			'subjectGenerationAvailable' => $this->createSubjectAvailability()->isAvailable(),
			'recipientLimits' => $this->getRecipientLimits(),
			'deviceContactsAvailable' => $this->isDeviceContactsAvailable(),
			'signaturesAvailable' => $this->isSignaturesAvailable(),
		] + $this->getLargeAttachmentInfo();
	}

	protected function isMailTemplateSaveAvailable(): bool
	{
		try
		{
			return $this->createCrmTemplateProvider()->canCreatePersonal(
				(int)($this->getCurrentUser()?->getId()),
			);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function resolveMailTemplateManagePath(): string
	{
		try
		{
			return $this->createCrmTemplateProvider()->isAvailable(
				(int)($this->getCurrentUser()?->getId()),
			)
				? '/crm/configs/mailtemplate/'
				: ''
			;
		}
		catch (\Throwable)
		{
			return '';
		}
	}

	/**
	 * @param string $colorScheme Color scheme of the application; anything but `dark`, a missing value included,
	 *        is read as the light one.
	 * @param array $themeColors Theme colors of the application; the mail module checks their format.
	 */
	public function getChainAction(
		int $id,
		int $messageType = self::CRM_MESSAGE_TYPE,
		string $act = '',
		string $mailUfMessageToken = '',
		string $colorScheme = '',
		array $themeColors = [],
	): ?MailMessageChain
	{
		if (!$this->includeMessageReadModules($messageType))
		{
			return null;
		}

		$mailMessageChain = $this->getFavoriteMessageReadService()
			->getChain($id, $messageType, $act, $mailUfMessageToken)
		;
		$this->applyBodyColorScheme($mailMessageChain->list, $colorScheme, $themeColors);

		return $mailMessageChain;
	}

	/**
	 * @param string $colorScheme Color scheme of the application; anything but `dark`, a missing value included,
	 *        is read as the light one.
	 * @param array $themeColors Theme colors of the application; the mail module checks their format.
	 */
	public function getMessageAction(
		int $id,
		bool $takeBody = false,
		bool $takeFiles = false,
		int $messageType = self::CRM_MESSAGE_TYPE,
		string $act = '',
		string $mailUfMessageToken = '',
		string $colorScheme = '',
		array $themeColors = [],
	): ?MailMessage
	{
		if (!$this->includeMessageReadModules($messageType))
		{
			return null;
		}

		$mailMessage = $this->getFavoriteMessageReadService()->getMessage(
			$id,
			$takeBody,
			$takeFiles,
			$messageType,
			$act,
			$mailUfMessageToken,
		);
		$this->applyBodyColorScheme([$mailMessage], $colorScheme, $themeColors);

		return $mailMessage;
	}

	/**
	 * Puts the copy of the body recolored for the requested color scheme into the response. The body of the
	 * message itself is never touched: the copy lives in the response and nowhere else. A refusal of the recolor
	 * is a normal outcome - the message keeps its original body and reports it as not themed.
	 *
	 * @param MailMessage[] $messages
	 * @param string $colorScheme Color scheme as the client reported it.
	 * @param array $themeColors Theme colors as the client reported them.
	 */
	private function applyBodyColorScheme(array $messages, string $colorScheme, array $themeColors): void
	{
		$messagesWithBody = [];

		foreach ($messages as $message)
		{
			if (!$message instanceof MailMessage)
			{
				continue;
			}

			$message->isBodyThemed = false;

			if (is_string($message->body) && $message->body !== '')
			{
				$messagesWithBody[] = $message;
			}
		}

		if ($messagesWithBody === [])
		{
			return;
		}

		try
		{
			if (!$this->isDarkColorSchemeRequested($colorScheme))
			{
				return;
			}

			// The recolor is released with the mail module: without it mobile mail shows the original colors.
			$bodyDarkThemeService = new BodyDarkThemeService();
		}
		catch (\Throwable)
		{
			return;
		}

		foreach ($messagesWithBody as $message)
		{
			try
			{
				$result = $bodyDarkThemeService->applyColorScheme(
					$message->body,
					BodyDarkThemeService::COLOR_SCHEME_DARK,
					$themeColors,
				);

				if ($result->themed)
				{
					$message->body = $result->body;
					$message->isBodyThemed = true;
				}
			}
			catch (\Throwable)
			{
				$message->isBodyThemed = false;
			}
		}
	}

	/**
	 * Boundary of the controller for the reported color scheme: anything but the dark one, an unknown and a
	 * missing value included, is the light one.
	 */
	private function isDarkColorSchemeRequested(string $colorScheme): bool
	{
		return strtolower(trim($colorScheme)) === BodyDarkThemeService::COLOR_SCHEME_DARK;
	}

	private function includeMessageReadModules(int $messageType): bool
	{
		return $this->includeMessageReadModule('mail')
			&& ($messageType !== self::CRM_MESSAGE_TYPE || $this->includeMessageReadModule('crm'));
	}

	protected function includeMessageReadModule(string $moduleId): bool
	{
		return Loader::includeModule($moduleId);
	}

	/**
	 * The disk objects of the attachments are asked for only when the large attachment scenario is available,
	 * and the availability is the same answer the send is composed with: a read of a card must not pay for a
	 * conversion of a heavy set that this portal cannot offer anyway. With the scenario off a card is read
	 * exactly the way it was read before the scenario existed.
	 */
	protected function getFavoriteMessageReadService(): FavoriteMessageReadService
	{
		return new FavoriteMessageReadService(
			new FavoriteMessageEnricher(),
			attachmentDiskObjectsEnabled: $this->isLargeAttachmentAvailable(),
		);
	}

	/**
	 * @param Address[] $addressList
	 * @param string $fromField
	 *
	 * @return void
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 * @throws ReflectionException
	 */
	private function appendMailContacts(array $addressList, string $fromField = ''): void
	{
		$currentUser = $this->getCurrentUser();
		if (!$currentUser)
		{
			return;
		}

		$fromField = mb_strtoupper($fromField);
		if (
			!in_array($fromField, [
				MailContactTable::ADDED_TYPE_TO,
				MailContactTable::ADDED_TYPE_CC,
				MailContactTable::ADDED_TYPE_BCC,
			], true)
		)
		{
			$fromField = MailContactTable::ADDED_TYPE_TO;
		}

		$allEmails = [];
		$contactsData = [];

		foreach ($addressList as $address)
		{
			$allEmails[] = mb_strtolower($address->getEmail());
			$contactsData[] = [
				'USER_ID' => $currentUser->getId(),
				'NAME' => $address->getName(),
				'ICON' => MailContact::getIconData($address->getEmail(), $address->getName()),
				'EMAIL' => $address->getEmail(),
				'ADDED_FROM' => $fromField,
			];
		}

		MailContactTable::addContactsBatch($contactsData);

		$mailContacts = MailContactTable::query()
			->addSelect('ID')
			->where('USER_ID', $currentUser->getId())
			->whereIn('EMAIL', $allEmails)
			->exec();

		$lastRcpt = [];
		while ($contact = $mailContacts->fetch())
		{
			$lastRcpt[] = 'MC'. $contact['ID'];
		}

		if (count($lastRcpt) > 0)
		{
			FinderDestTable::merge([
				'USER_ID' => $currentUser->getId(),
				'CONTEXT' => 'MAIL_LAST_RCPT',
				'CODE' => $lastRcpt,
			]);
		}
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function isSenderLimitReached(string $fromEmail, int $recipientsCount, int $mailboxId): bool
	{
		$emailDailyLimit = $this->getSenderEmailLimit($fromEmail, $mailboxId);
		if ($emailDailyLimit <= 0)
		{
			return false;
		}

		$emailCounter = new SenderSendCounter();
		$limit = $emailCounter->get($fromEmail);

		return ($limit + $recipientsCount) > $emailDailyLimit;
	}

	protected function getSenderEmailLimit(string $fromEmail, int $mailboxId): ?int
	{
		if ($mailboxId > 0 && $this->supportsSenderIdentity())
		{
			return Sender::getEmailLimit($fromEmail, Identity::fromMailbox($mailboxId));
		}

		return Sender::getEmailLimit($fromEmail);
	}

	protected function supportsSenderIdentity(): bool
	{
		return class_exists(Identity::class)
			&& method_exists(Identity::class, 'fromMailbox')
			&& method_exists(Sender::class, 'getEmailLimit')
			&& (new \ReflectionMethod(Sender::class, 'getEmailLimit'))->getNumberOfParameters() >= 2
		;
	}

	/**
	 * @throws LoaderException
	 */
	private function isDailyPortalLimitReached(int $recipientsCount): bool
	{
		if (!isModuleInstalled('bitrix24') || !Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$counter = new MailCounter();
		$limit = $counter->getDailyLimit();

		return $limit > 0 && MailCounter::checkLimit($limit, $counter->get() + $recipientsCount);
	}

	/**
	 * @throws LoaderException
	 */
	private function isMonthPortalLimitReached(int $recipientsCount): bool
	{
		if (!isModuleInstalled('bitrix24') || !Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$counter = new MailCounter();
		$limit = $counter->getLimit();

		return $limit > 0 && MailCounter::checkLimit($limit, $counter->getMonthly() + $recipientsCount);
	}

	private function generateMessageId(string $hostname): string
	{
		// @TODO: more entropy
		return sprintf(
			'<bx.mail.%x.%x@%s>',
			time(),
			random_int(0, 0xffffff),
			$hostname
		);
	}

	private function getHostname()
	{
		static $hostname;
		if (empty($hostname))
		{
			$hostname = COption::getOptionString('main', 'server_name', '') ?: 'localhost';
			if (defined('BX24_HOST_NAME') && BX24_HOST_NAME !== '')
			{
				$hostname = BX24_HOST_NAME;
			}
			elseif (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '')
			{
				$hostname = SITE_SERVER_NAME;
			}
		}

		return $hostname;
	}

	/**
	 * Attachment copies of the draft the client says it is sending, by the file id of the copy: the file it
	 * was copied from, the Disk object it was made of and its own Disk object. Who owns which file is
	 * decided by the mail module: the draft storage is its own, and the ownership rules of a draft belong
	 * there together with it.
	 *
	 * @return array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}>
	 */
	private function getDraftAttachmentIds(
		int $userId,
		mixed $draftId,
		mixed $expectedRevision,
		mixed $expectedClientId = null,
	): array
	{
		if (!self::isDraftApiAvailable())
		{
			return [];
		}

		$hasSourceObject = self::hasDraftCopySourceObjectApi();
		$attachmentIds = [];
		$attachments = DraftSendService::getOwnedAttachments(
			DraftSendClaim::forMail($userId, $draftId, $expectedRevision, $expectedClientId),
		);
		foreach ($attachments as $attachment)
		{
			$attachmentIds[$attachment->fileId] = [
				'sourceFileId' => $attachment->sourceFileId,
				'sourceObjectId' => $hasSourceObject ? $attachment->sourceObjectId : 0,
				'objectId' => $attachment->objectId,
			];
		}

		return $attachmentIds;
	}

	/**
	 * Confirms the converted sets of this message through the large attachment adapter of the mail module.
	 *
	 * @param array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}> $draftAttachmentIds
	 * @param int[] $draftSetSourceObjectIds Source Disk objects of the sets the claimed draft owns.
	 */
	protected function prepareLargeAttachments(
		int $userId,
		array $data,
		array $fileTokens,
		array $draftAttachmentIds,
		array $draftSetSourceObjectIds,
		string $messageBody,
	): Main\Result
	{
		return $this->createLargeAttachmentSendPreparation()->prepareForSend(
			$userId,
			$data,
			$this->collectAllowedAttachmentIds(
				$fileTokens,
				$draftAttachmentIds,
				$draftSetSourceObjectIds,
			),
			$messageBody,
		);
	}

	/**
	 * Source Disk objects of the converted sets the claimed draft owns. A restored set names its files by
	 * those source objects, not by the copies the draft made, so the send contract of a restored set is
	 * confirmed against them. Only the sets this draft really owns are trusted: taking them from the claim
	 * keeps the guarantee that a set may not be swapped for the files of another message.
	 *
	 * @return int[]
	 */
	protected function getDraftLargeAttachmentSourceIds(int $userId, array $data): array
	{
		if (!self::hasDraftLargeAttachmentApi())
		{
			return [];
		}

		$sourceIds = [];
		$draftSets = DraftSendService::getOwnedLargeAttachments(DraftSendClaim::forMail(
			$userId,
			$data['draftId'] ?? null,
			$data['draftRevision'] ?? null,
			$data['draftClientId'] ?? null,
		));
		foreach ($draftSets as $draftSet)
		{
			foreach ((array)($draftSet['sourceFileIds'] ?? []) as $id)
			{
				if ((int)$id > 0)
				{
					$sourceIds[] = (int)$id;
				}
			}
		}

		return $sourceIds;
	}

	/**
	 * The controller is where the send is composed, so the adapter is handed the event manager from here
	 * instead of reaching for the one of the process on its own.
	 *
	 * The availability is handed over as well, and it is the answer of this controller: the send must be able
	 * to be rolled back by the mobile flag alone. Left to itself the adapter falls back to the option of the
	 * mail module, so a form opened before the flag was switched off would still get its set confirmed and
	 * the letter would leave as a service link the rollback has just revoked.
	 *
	 * The tariff is handed over from the same method the compose preload answers by, so the answer the form
	 * was given and the answer the send confirms a set with cannot drift apart. It stays a term of its own:
	 * the availability of the scenario says nothing about the tariff, and folding one into the other would
	 * take the Disk away from every attachment of a portal that simply does not pay for the service link.
	 */
	protected function createLargeAttachmentSendPreparation(): SendPreparation
	{
		return new SendPreparation(
			featureEnabled: $this->isLargeAttachmentAvailable(),
			eventManager: Main\EventManager::getInstance(),
			tariffAllowed: $this->isLargeAttachmentTariffAvailable(),
		);
	}

	/**
	 * The placement a new object of the Disk is created through, or null while the scenario is not on for
	 * this portal. Everything that talks to the Disk lives in the adapter, so the send path only hands
	 * identifiers over. A copy of an attachment of a quote is a new placement, so it obeys the whole gate:
	 * with the scenario declared off the copy is made the way it was made before the move.
	 */
	protected function createAttachmentPlacement(): ?AttachmentPlacement
	{
		return AttachmentPlacementFactory::createForNewAttachments();
	}

	/**
	 * The placement an object the form already reported is read through. It is not the one above: forbidding
	 * a new placement is not the same as revoking an identifier already issued, and a form opened before the
	 * rollback - or a restored draft - still names files that exist and the sender may read. What may be read
	 * is decided by the permission model of the Disk, in the context of the sender.
	 */
	protected function createAttachmentPlacementReader(): ?AttachmentPlacement
	{
		return AttachmentPlacementFactory::createForExistingObjects();
	}

	/**
	 * The real name of every attachment the reported objects of the Disk stand for, by the id of the stored
	 * file that leaves with the message.
	 *
	 * An object names its own stored file, and that alone answers for an ordinary attachment and for the copy
	 * a draft owns when the form reports the object of the copy. The form may just as well still hold the
	 * object the copy was made of - the same compose session that made the copy - and then the name has to be
	 * put on the file of the copy anyway, because the copy is what leaves with the message. The link between
	 * the two is not taken from the client: it comes from the records of the draft the mail module released.
	 *
	 * The name comes from the object and never from the row of the attachment, the way the web reads it
	 * (mail.client/ajax.php, $diskFile->getName()). The service folder of the drafts is shared for a month and
	 * objects are created with uniqueness, so the name of a copy may arrive with a suffix - 'report (1).pdf'.
	 * That is the same name the web would have sent, and it is the deliberate answer here as well.
	 *
	 * @param array<int, array{fileId: int, name: string}> $resolvedDiskFiles by Disk object id
	 * @param array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}> $draftAttachmentIds
	 *
	 * @return array<int, string> real name by stored file id
	 */
	protected function resolveAttachmentNamesFromObjects(
		array $resolvedDiskFiles,
		array $draftAttachmentIds,
	): array
	{
		$names = [];
		foreach ($resolvedDiskFiles as $resolved)
		{
			$fileId = (int)($resolved['fileId'] ?? 0);
			$name = trim((string)($resolved['name'] ?? ''));
			if ($fileId > 0 && $name !== '')
			{
				$names[$fileId] = $name;
			}
		}

		foreach ($draftAttachmentIds as $copyFileId => $ids)
		{
			$copyFileId = (int)$copyFileId;
			$sourceObjectId = (int)($ids['sourceObjectId'] ?? 0);
			if ($copyFileId <= 0 || $sourceObjectId <= 0 || isset($names[$copyFileId]))
			{
				continue;
			}

			// The object the copy was made of answers for the copy only while it really is its source: the
			// stored file behind that object has to be the one the draft remembers.
			if ((int)($resolvedDiskFiles[$sourceObjectId]['fileId'] ?? 0) !== (int)($ids['sourceFileId'] ?? 0))
			{
				continue;
			}

			$name = trim((string)($resolvedDiskFiles[$sourceObjectId]['name'] ?? ''));
			if ($name !== '')
			{
				$names[$copyFileId] = $name;
			}
		}

		return $names;
	}

	/**
	 * The name the recipient sees for an attachment. An attachment of a received message is stored in b_file
	 * under the md5 of its name, by both paths that write one (Mail\Helper\AttachmentHelper for the current
	 * sync, CMailMessage::addAttachment for the legacy one), so the name of the stored file is a hash without
	 * an extension. The real name belongs to the row of the attachment and to the object of the Disk the row
	 * is registered as, and the web reads it from the object as well (mail.client/ajax.php, $diskFile->getName()).
	 *
	 * A file that never came from a message - an ordinary upload of the compose form - is stored under its own
	 * name, and nothing is known about it apart from that, so the stored name stays the answer.
	 *
	 * @param array<int, string> $attachmentNames real name by stored file id
	 */
	protected function resolveOutgoingAttachmentName(array $file, int $fileId, array $attachmentNames): string
	{
		$realName = trim((string)($attachmentNames[$fileId] ?? ''));

		return $realName !== '' ? $realName : (string)($file['name'] ?? '');
	}

	/**
	 * Whether the bytes of a stored file can be handed to the transport at all. It is the only question the
	 * assembly asks about a file: the size is none of its business, because an empty file is an attachment
	 * of its own and the web sends it without asking (mail.client/ajax.php). Dropping it here took away a
	 * file the person had just chosen and said nothing about it.
	 *
	 * @param mixed $file The answer of CFile::MakeFileArray for the stored file.
	 */
	protected function isStoredFileReadable(mixed $file): bool
	{
		return is_array($file) && !empty($file['tmp_name']);
	}

	/**
	 * The attachments this message may not silently leave without: the copies the claimed draft owns, and
	 * the stored files behind the objects of the Disk the form reported. Each of them stands for an
	 * attachment the person chose and the form still shows, so a stored file that cannot be read refuses the
	 * whole send - the same rule an object that did not resolve already obeys, a hundred lines above.
	 *
	 * An attachment of a quote is deliberately absent. A quoted row whose file is gone keeps being skipped:
	 * that is the literal behaviour of the web and the risk this branch declared to the reviewer.
	 *
	 * @param int[] $draftFileIds Stored files of the copies the claimed draft owns.
	 * @param array<int, int> $placedObjectIds Disk object id by the stored file it was resolved into.
	 *
	 * @return array<int, true>
	 */
	protected function collectAttachmentsThatMayNotBeDropped(
		array $draftFileIds,
		array $placedObjectIds,
	): array
	{
		$required = [];
		foreach ([$draftFileIds, array_keys($placedObjectIds)] as $fileIds)
		{
			foreach ($fileIds as $fileId)
			{
				if ((int)$fileId > 0)
				{
					$required[(int)$fileId] = true;
				}
			}
		}

		return $required;
	}

	/**
	 * Takes the confirmed files out of the message before it is assembled. They leave with the service link
	 * instead of the message, so they must count neither as its attachments nor towards its total size.
	 *
	 * The identifiers live in two separate spaces that must not be mixed. An object of the Disk is named
	 * n<id> by the form; a stored file of b_file is a bare number (a legacy attachment, and the file a draft
	 * copy remembers its source by). A number in one space equal to a number in the other belongs to two
	 * different tables, so an object id may never exclude an attachment of the file space and the other way
	 * round.
	 *
	 * A draft copy is covered by a confirmed set when the file it was made from, the file itself or the
	 * object it became belongs to the set. A covered copy leaves the draft records and brings its own
	 * identifiers into the exclusion, so the token of the copy object the form still reports (n<copy object>)
	 * falls out of the message as well instead of being sent raw and failing the send by its own size.
	 *
	 * @param array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}> $draftAttachmentIds
	 * @param int[] $confirmedObjectIds Disk objects the validator confirmed.
	 * @param int[] $confirmedStoredFileIds b_file ids behind those objects.
	 *
	 * @return array{0: array, 1: array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}>}
	 */
	protected function excludeConfirmedAttachments(
		array $fileTokens,
		array $draftAttachmentIds,
		array $confirmedObjectIds,
		array $confirmedStoredFileIds,
	): array
	{
		if ($confirmedObjectIds === [] && $confirmedStoredFileIds === [])
		{
			return [$fileTokens, $draftAttachmentIds];
		}

		$excludedObjectIds = array_fill_keys(array_map('intval', $confirmedObjectIds), true);
		$excludedStoredFileIds = array_fill_keys(array_map('intval', $confirmedStoredFileIds), true);

		$keptDraftAttachmentIds = [];
		foreach ($draftAttachmentIds as $fileId => $ids)
		{
			$covered = isset($excludedStoredFileIds[(int)$ids['sourceFileId']])
				|| isset($excludedObjectIds[(int)$ids['objectId']])
				|| isset($excludedStoredFileIds[(int)$fileId])
			;
			if (!$covered)
			{
				$keptDraftAttachmentIds[$fileId] = $ids;

				continue;
			}

			// The copy leaves with the confirmed set, so the object the form still reports for it and the
			// copy file itself join the exclusion, each in its own id space.
			$excludedObjectIds[(int)$ids['objectId']] = true;
			$excludedStoredFileIds[(int)$fileId] = true;
		}

		$keptFileTokens = array_values(array_filter(
			$fileTokens,
			static function (mixed $token) use ($excludedObjectIds, $excludedStoredFileIds): bool
			{
				$objectId = AttachmentPlacement::parseObjectReference($token);
				if ($objectId !== null)
				{
					return !isset($excludedObjectIds[$objectId]);
				}

				if (is_numeric($token))
				{
					return !isset($excludedStoredFileIds[(int)$token]);
				}

				return true;
			},
		));

		return [$keptFileTokens, $keptDraftAttachmentIds];
	}

	/**
	 * The stored files behind the Disk objects of a confirmed set. A draft copy remembers its source by
	 * that id, so it is the id the exclusion of confirmed attachments has to know.
	 *
	 * @param int[] $confirmedObjectIds
	 *
	 * @return int[]
	 */
	protected function resolveStoredFileIds(
		?AttachmentPlacement $placement,
		array $confirmedObjectIds,
		int $userId,
	): array
	{
		if ($placement === null || $confirmedObjectIds === [])
		{
			return [];
		}

		return array_values($placement->resolveAttachments(
			array_map('intval', $confirmedObjectIds),
			$userId,
		));
	}

	/**
	 * The Disk objects of the draft copies that leave the message with the sets this request confirmed, or
	 * null when a confirmed set of the draft names a source object no copy of the claim answers for.
	 *
	 * A set names its files by the source objects it was built from, and a copy of the draft is otherwise
	 * recognised by the stored file behind such an object. An object deleted after the autosave has no stored
	 * file left to read, and the copy would stay in the message: a heavy one then fails the send by its own
	 * size, exactly what the set was made to avoid. So the copy is bound to its set the way the mail module
	 * binds it when it hands a draft back - by the source object the copy was made of, which the claim
	 * releases together with the copy. Nothing of the binding comes from the request, and a set the claim
	 * does not release takes no copy out of the message.
	 *
	 * The draft never carries a set without a copy of every source object of it: the mail module refuses to
	 * store one. A source object left without a copy is therefore a draft the send cannot read, and refusing
	 * is the only answer that does not put a raw copy of a converted file into the message.
	 *
	 * @param array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}> $draftAttachmentIds
	 *        Copies the claim released.
	 * @param int[] $confirmedObjectIds Disk objects the validator confirmed.
	 * @param int[] $draftSetSourceObjectIds Source Disk objects of the sets the claimed draft owns.
	 *
	 * @return int[]|null
	 */
	protected function resolveDraftCopiesOfConfirmedSets(
		array $draftAttachmentIds,
		array $confirmedObjectIds,
		array $draftSetSourceObjectIds,
	): ?array
	{
		if ($draftSetSourceObjectIds === [] || $confirmedObjectIds === [])
		{
			return [];
		}

		$copyObjectIdsBySource = [];
		foreach ($draftAttachmentIds as $ids)
		{
			$sourceObjectId = (int)($ids['sourceObjectId'] ?? 0);
			$objectId = (int)($ids['objectId'] ?? 0);
			if ($sourceObjectId > 0 && $objectId > 0)
			{
				$copyObjectIdsBySource[$sourceObjectId] = $objectId;
			}
		}

		$confirmed = array_fill_keys(array_map('intval', $confirmedObjectIds), true);
		$copyObjectIds = [];
		foreach ($draftSetSourceObjectIds as $sourceObjectId)
		{
			$sourceObjectId = (int)$sourceObjectId;
			if ($sourceObjectId <= 0 || !isset($confirmed[$sourceObjectId]))
			{
				continue;
			}

			if (!isset($copyObjectIdsBySource[$sourceObjectId]))
			{
				return null;
			}

			$copyObjectIds[$copyObjectIdsBySource[$sourceObjectId]] = $copyObjectIdsBySource[$sourceObjectId];
		}

		return array_values($copyObjectIds);
	}

	/**
	 * Identifiers actually attached to this message. The validator is allowed to confirm nothing beyond
	 * them, otherwise a set could be swapped for files of another message.
	 *
	 * @param array<int, array{sourceFileId: int, sourceObjectId: int, objectId: int}> $draftAttachmentIds
	 * @param int[] $draftLargeAttachmentSourceIds Source Disk objects of the converted sets the draft owns.
	 *
	 * @return int[]
	 */
	protected function collectAllowedAttachmentIds(
		array $fileTokens,
		array $draftAttachmentIds,
		array $draftLargeAttachmentSourceIds = [],
	): array
	{
		$allowedFileIds = [];
		foreach ($fileTokens as $token)
		{
			// An attachment stored in the Disk is named by its object, and a set of the scenario is made of
			// exactly those objects. A bare number names b_file and must stay in that separate id space.
			$objectId = AttachmentPlacement::parseObjectReference($token);
			if ($objectId !== null)
			{
				$allowedFileIds[$objectId] = true;
			}
		}

		foreach ($draftAttachmentIds as $ids)
		{
			// Only the Disk object of a copy belongs here: a contract of the scenario names objects, and the
			// stored file behind a copy lives in the b_file space the validator cannot tell apart.
			$objectId = (int)($ids['objectId'] ?? 0);
			if ($objectId > 0)
			{
				$allowedFileIds[$objectId] = true;
			}
		}

		// A restored set carries the source objects it was built from; those objects are no longer among the
		// file tokens, so without this the contract of a restored set would name an id the message cannot show.
		foreach ($draftLargeAttachmentSourceIds as $id)
		{
			if ((int)$id > 0)
			{
				$allowedFileIds[(int)$id] = true;
			}
		}

		return array_keys($allowedFileIds);
	}

	/**
	 * Drafts may be missing from the installed mail module: the two modules are released on their own, so
	 * the mobile client simply does without drafts instead of failing the send.
	 */
	private static function isDraftApiAvailable(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(DraftSendService::class)
			&& method_exists(DraftSendService::class, 'getOwnedAttachments')
			&& method_exists(DraftSendService::class, 'completeAfterSuccessfulSend')
			&& class_exists(DraftSendClaim::class)
			&& method_exists(DraftSendClaim::class, 'forMail')
		;
	}

	/**
	 * The source object of a draft copy is released by the mail module on its own, so a build without it
	 * keeps recognising a copy by the stored file behind its source object, as the send did before.
	 */
	private static function hasDraftCopySourceObjectApi(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(DraftOwnedAttachment::class)
			&& property_exists(DraftOwnedAttachment::class, 'sourceObjectId')
		;
	}

	/**
	 * The converted set contract of the mail module is released on its own, so a build without it simply
	 * confirms nothing extra for a restored draft instead of failing every send.
	 */
	private static function hasDraftLargeAttachmentApi(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(DraftSendService::class)
			&& method_exists(DraftSendService::class, 'getOwnedLargeAttachments')
			&& class_exists(DraftSendClaim::class)
			&& method_exists(DraftSendClaim::class, 'forMail')
		;
	}

	private function hasUserAccessToMessage(int $messageId): bool
	{
		if (!Loader::includeModule('mail'))
		{
			return false;
		}

		$mailboxId = Helper\Mailbox::getIdByMessageId($messageId);
		if (!$mailboxId)
		{
			return false;
		}

		return MailboxAccess::hasCurrentUserAccessToMailbox($mailboxId, true);
	}

	private function applyVirtualFolderFilter(
		?string $virtualFolderKey,
		?string $tabId,
		array $mailboxIdsForFilter,
		array &$filterParams,
		int $userId,
	): array
	{
		switch ($virtualFolderKey)
		{
			case MessageFolder::VIRTUAL_ALL_MESSAGES:
				$mailboxIdsForFilter = array_map('intval', array_keys(MailboxTable::getUserMailboxes($userId)));
				$filterParams['MD5_DIRS'] = $tabId === self::TAB_SENT
					? MailboxDirectoryHelper::getOutcomeDirsMd5ForMailboxes($mailboxIdsForFilter)
					: MailboxDirectoryHelper::getSyncDirsMd5ForMailboxes($mailboxIdsForFilter);
				break;
			default:
				break;
		}

		return $mailboxIdsForFilter;
	}

	/**
	 * @param callable(int): int[]|null $accessibleMailboxIdsLoader
	 * @param callable(int[]): array<int, string[]>|null $excludedDirsLoader
	 * @param callable(int[]): array<int, string[]>|null $outcomeDirsLoader
	 * @return array{isFavoritesMode: bool, mailboxIds: int[], filterParams: array, filter: ?MessageFilter}
	 */
	private static function buildFavoritesFilterState(
		array $mailboxIds,
		array $filterParams,
		?string $virtualFolderKey,
		?string $tabId,
		int $userId,
		bool $featureEnabled,
		?array $presetFields = null,
		?callable $accessibleMailboxIdsLoader = null,
		?callable $excludedDirsLoader = null,
		?callable $outcomeDirsLoader = null,
	): array
	{
		$isFavoritesMode = $featureEnabled && $virtualFolderKey === self::VIRTUAL_FOLDER_FAVORITES;
		if (!$isFavoritesMode)
		{
			return [
				'isFavoritesMode' => false,
				'mailboxIds' => $mailboxIds,
				'filterParams' => $filterParams,
				'filter' => null,
			];
		}

		$accessibleMailboxIdsLoader ??= static fn (int $userId): array =>
			array_map('intval', array_keys(MailboxTable::getUserMailboxes($userId, true)));
		$excludedDirsLoader ??= static fn (array $mailboxIds): array => [];
		$outcomeDirsLoader ??= static fn (array $mailboxIds): array => [];

		$mailboxIds = array_values(array_unique(array_filter(
			array_map('intval', $accessibleMailboxIdsLoader($userId)),
			static fn (int $mailboxId): bool => $mailboxId > 0,
		)));
		$filterParams = self::prepareFavoritesFilterParams(
			$presetFields ?? $filterParams,
			$tabId === self::TAB_UNREAD,
		);
		$filter = new MessageFilter($mailboxIds === [] ? [0] : $mailboxIds, $filterParams, userId: $userId);
		$filter->addDirectoryScopes(
			$excludedDirsLoader($mailboxIds),
			$tabId === self::TAB_SENT ? $outcomeDirsLoader($mailboxIds) : null,
		);

		return [
			'isFavoritesMode' => true,
			'mailboxIds' => $mailboxIds,
			'filterParams' => $filterParams,
			'filter' => $filter,
		];
	}

	private static function prepareFavoritesFilterParams(
		array $filterParams,
		bool $unreadOnly = false,
	): array
	{
		unset($filterParams['DIR'], $filterParams['MD5_DIRS'], $filterParams['LABEL_ID']);

		$filterParams['IS_FAVORITE'] = 'Y';
		if ($unreadOnly)
		{
			$filterParams['IS_SEEN'] = 'N';
		}

		return $filterParams;
	}

	protected function applyTabFilter(?string $tabId, $currentDir, $mailboxDirsHelper, array &$filterParams)
	{
		if ($tabId !== self::TAB_SENT)
		{
			return $currentDir;
		}

		$targetPath = $mailboxDirsHelper->getOutcomePath(true);
		$targetDir = $mailboxDirsHelper->getDirByPath($targetPath);

		if ($targetDir?->isSync())
		{
			$filterParams['DIR'] = $targetPath;

			return $targetDir;
		}

		return $currentDir;
	}
}
