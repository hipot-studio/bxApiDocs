<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Controller\ActionFilter\ConnectionRequestResponsibleAdminAccess;
use Bitrix\Mail\Controller\ActionFilter\MassConnectAccess;
use Bitrix\Mail\Controller\ActionFilter\MassMailboxActionsAccess;
use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\Dto\MailboxConnect\GeneralSettingsPatch;
use Bitrix\Mail\Internal\Service\Mailbox\GeneralSettingsService;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarChange;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarService;
use Bitrix\Mail\Helper\Dto\Mailbox\BulkActionResult;
use Bitrix\Mail\Helper\Mailbox\MailMassConnect;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Enum\MailboxConnectionRequestStatus;
use Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Helper\Mailbox\MailboxSettingsConfig;
use Bitrix\Mail\Helper\Mailbox\PasswordlessConnectHelper;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\OAuth;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Mail\Public\Service\Message\Conversation\ConversationSettingsService;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Validation\Engine\AutoWire\ValidationParameter;
use Bitrix\Main\Web\Uri;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\HumanResources\Service\Container;
use Psr\Log\LoggerInterface;

/**
 * Class MailboxConnecting
 * Methods for connecting a mailbox and getting the data required for connection
 *
 * @package Bitrix\Mail\Controller
 */
class MailboxConnecting extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return
			[
				new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, 'application/x-www-form-urlencoded']),
				new Main\Engine\ActionFilter\Authentication(),
				new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_GET, Main\Engine\ActionFilter\HttpMethod::METHOD_POST],
				),
				new Intranet\ActionFilter\IntranetUser(),
			];
	}

	public function configureActions(): array
	{
		$massConnectFilter = ['+prefilters' => [new MassConnectAccess()]];

		// A mass action writes to every mailbox of the batch, so it answers a POST carrying the token
		// of the session and nothing else: the default filters of this controller let a GET through.
		// Its gate carries the switch of the bulk actions as well, which is why it is not the gate of
		// the passwordless connect above: that flow is no action of the grid.
		$massActionFilter = ['+prefilters' => [
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			new MassMailboxActionsAccess(),
		]];

		return [
			'updateMailbox' => ['+prefilters' => [
				new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
				new Main\Engine\ActionFilter\Csrf(),
			]],
			'updateGeneralSettings' => ['+prefilters' => [
				new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
				new Main\Engine\ActionFilter\Csrf(),
			]],
			'createPasswordlessRequest' => $massConnectFilter,
			'validateConnectionSettings' => $massConnectFilter,
			'resendPasswordlessRequest' => $massConnectFilter,
			'deletePasswordlessRequest' => $massConnectFilter,
			'getPasswordlessRequestsCount' => $massConnectFilter,
			'massDisconnectMailboxes' => $massActionFilter,
			'massSetCrmIntegration' => $massActionFilter,
			'massSetCalendarIntegration' => $massActionFilter,
			'connectMailboxByConnectionRequest' => ['+prefilters' => [new ConnectionRequestResponsibleAdminAccess()]],
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new ValidationParameter(
				MailboxConnectDTO::class,
				fn() => MailboxConnectDTO::createFromRequest($this->getRequest()),
			),
		];
	}

	/**
	 * @param string $serviceName
	 * @param string $type
	 * @return string|null
	 */
	public function getUrlOauthAction(string $serviceName, string $type = OAuth::WEB_TYPE): ?string
	{
		$oauthHelper = $this->resolveOAuthHelper($serviceName);
		if ($oauthHelper === null)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_MAIL_MODULE_OAUTH_SERVICE_IS_NOT_CONFIGURED')));

			return null;
		}

		return $oauthHelper->getUrl($type);
	}

	/**
	 * Resolves OAuth helper by OAuth service name (e.g. "google") or by mail service NAME (e.g. "gmail").
	 *
	 * @param string $serviceName
	 * @return OAuth|null
	 */
	private function resolveOAuthHelper(string $serviceName): ?OAuth
	{
		$oauthHelper = OAuth::getInstance($serviceName);
		if ($oauthHelper instanceof OAuth)
		{
			return $oauthHelper;
		}

		$oauthHelper = MailServicesTable::getOAuthHelper(['NAME' => $serviceName]);

		return $oauthHelper instanceof OAuth ? $oauthHelper : null;
	}

	/**
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getServicesAction(): array
	{
		$services = Mailbox::getServices();

		foreach ($services as &$service)
		{
			if (
				MailServicesTable::getOAuthHelper(['NAME' => $service['name']]) instanceof OAuth
				&& MailboxConnector::isOauthSmtpEnabled($service['name'] ?? '')
			)
			{
				$service['oauthMode'] = true;
			}
			else
			{
				$service['oauthMode'] = false;
			}
		}

		return $services;
	}

	public function getConnectionUrlAction(): string
	{
		$uri = new Uri(UrlManager::getInstance()->getHostUrl() . '/bitrix/tools/mobile_oauth.php');

		return $uri->getLocator();
	}

	public function connectMailboxAction(MailboxConnectDTO $mailboxConnectDTO): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		$targetUserId = $mailboxConnectDTO->userIdToConnect ?? $currentUserId;
		$isSelfConnect = $targetUserId === $currentUserId;

		if ($isSelfConnect)
		{
			if (!MailboxAccess::hasCurrentUserAccessToAddMailbox())
			{
				$this->addError(new Error('Mailbox connection is not allowed'));

				return [];
			}
		}
		else
		{
			if (!MailAccess::hasCurrentUserAccessToConnectMailboxToUser($targetUserId))
			{
				$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION_TO_CONNECT_TO_USER')));

				return [];
			}
		}

		$mailboxConnectDTO->userIdToConnect = $targetUserId;

		$mailboxConnector = new MailboxConnector();
		$result = $mailboxConnectDTO->crmOptions !== null
			? $mailboxConnector->connectMailboxWithCustomCrm($mailboxConnectDTO)
			: $mailboxConnector->connectMailboxWithDefaultCrm($mailboxConnectDTO);
		$this->addErrors($mailboxConnector->getErrors());

		$mailboxId = (int)($result['id'] ?? 0);

		if ($mailboxId > 0)
		{
			if (empty($this->getErrors()))
			{
				$this->captureConversationOnboardingEligibility();
				\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
			}
		}

		return $result;
	}

	/**
	 * Captures the right to the conversation tour strictly before `previous_seen_mailbox_id` is
	 * written: that value is the only trace of a visit to Mail made before the release, and the right
	 * is decided by that trace alone. The capture writes once and never revises itself, so calling it
	 * from every point that overwrites the trace is safe.
	 */
	private function captureConversationOnboardingEligibility(): void
	{
		$previousSeenMailboxId = (int)\CUserOptions::getOption('mail', 'previous_seen_mailbox_id', 0);

		(new ConversationSettingsService())->captureOnboardingEligibility(
			(int)CurrentUser::get()->getId(),
			$previousSeenMailboxId > 0,
		);
	}

	public function connectMailboxByConnectionRequestAction(
		int $connectionRequestId,
		MailboxConnectDTO $mailboxConnectDTO,
	): array
	{
		$requestService = new MailboxConnectionRequestService();
		$request = $requestService->getRequestById($connectionRequestId);

		if ($request === null)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_CONNECTION_REQUEST_NOT_FOUND')));

			return [];
		}

		if ($request['STATUS'] !== MailboxConnectionRequestStatus::Pending->value)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_CONNECTION_REQUEST_NOT_PENDING')));

			return [];
		}

		$mailboxConnectDTO->userIdToConnect = (int)$request['REQUESTER_ID'];

		$mailboxConnector = new MailboxConnector();
		$result = $mailboxConnector->connectMailboxWithCustomCrm($mailboxConnectDTO);
		$this->addErrors($mailboxConnector->getErrors());

		$mailboxId = (int)($result['id'] ?? 0);

		if ($mailboxId <= 0 || !empty($this->getErrors()))
		{
			return $result;
		}

		$completeResult = $requestService->completeRequest($connectionRequestId, $mailboxId);
		if (!$completeResult->isSuccess())
		{
			$this->addErrors($completeResult->getErrors());

			return $result;
		}

		return [
			'id' => $mailboxId,
			'senderName' => $result['senderName'] ?? null,
			'connectionRequestCompleted' => true,
			'pendingCount' => $completeResult->getData()['pendingCount'] ?? null,
		];
	}

	public function getMailboxAction(int $mailboxId): array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION')));

			return [];
		}

		$mailboxConnector = new MailboxConnector();
		$data = $mailboxConnector->getMailboxDataSafe($mailboxId);

		if (empty($data))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_MAILBOX_NOT_FOUND')));

			return [];
		}

		return $data;
	}

	/**
	 * The one path of the edit screen that goes to the OAuth provider. The form asks for it after it is
	 * drawn, so a slow or a revoked token delays a picture and a prefilled hint instead of the screen.
	 */
	public function getOauthUserAction(int $mailboxId): ?array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION')));

			return null;
		}

		return (new MailboxConnector())->getOAuthUserProfile($mailboxId);
	}

	public function getSettingsConfigAction(): array
	{
		return MailboxSettingsConfig::getClientConfig();
	}

	public function checkEmailAvailabilityAction(int $serviceId, string $email, string $oauthUid): bool
	{
		if (!MailboxAccess::hasCurrentUserAccessToAddMailbox())
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION')));

			return false;
		}

		if ($oauthUid === '')
		{
			return false;
		}

		$service = MailServicesTable::getList([
			'filter' => [
				'=ID' => $serviceId,
				'=ACTIVE' => 'Y',
				'=SERVICE_TYPE' => 'imap',
			],
		])->fetch();

		if (empty($service))
		{
			return false;
		}

		$oauthHelper = MailServicesTable::getOAuthHelper($service);
		if (!$oauthHelper instanceof OAuth)
		{
			return false;
		}

		$oauthHelper->getStoredToken($oauthUid);

		$mailbox = [
			'USE_TLS' => $service['ENCRYPTION'],
			'LOGIN' => $email,
			'SERVER' => $service['SERVER'],
			'PORT' => $service['PORT'],
			'PASSWORD' => $oauthHelper->buildMeta(),
		];

		$error = null;
		$result = Helper::getImapUnseen($mailbox, 'inbox', $error);

		if ($result === false && !empty($error))
		{
			AddMessage2Log(
				sprintf(
					'checkEmailAvailability failed: service=%d, email=%s, error=%s',
					$serviceId,
					$email,
					is_array($error) ? json_encode($error) : (string)$error,
				),
				'mail',
				2,
				true,
			);
		}

		return $result !== false;
	}

	public function updateGeneralSettingsAction(int $mailboxId, array $patch): array
	{
		$parsed = $this->parseAvatarChange($this->getSaveParameters());
		if (!$parsed->isSuccess())
		{
			$this->addErrors($parsed->getErrors());

			return [];
		}
		$result = (new GeneralSettingsService(allowCanceled: true))->update(
			$mailboxId,
			GeneralSettingsPatch::fromArray($patch),
			$parsed->getData()['change'] ?? null,
		);
		$this->addErrors($result->getErrors());

		return $result->isSuccess() ? $result->getData() : [];
	}

	private function getSaveParameters(): array
	{
		$parameters = [];
		foreach ($this->getSourceParametersList() ?? [] as $source)
		{
			$parameters += $source instanceof Main\Type\Dictionary ? $source->toArray() : $source;
		}
		$parameters += $this->getRequest()->getPostList()->toArray();
		$parameters += $this->getRequest()->getJsonList()->toArray();

		return $parameters;
	}

	private function parseAvatarChange(array $parameters): Main\Result
	{
		if (!Feature::isMailboxAvatarAvailable())
		{
			return new Main\Result();
		}

		return array_key_exists('avatar', $parameters)
			? AvatarService::localizeErrors(AvatarChange::fromArray($parameters['avatar']))
			: new Main\Result();
	}

	public function updateMailboxAction(int $mailboxId, MailboxConnectDTO $dto): array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION')));

			return [];
		}

		$mailboxConnector = new MailboxConnector();
		$parameters = $this->getSaveParameters();
		$parsed = $this->parseAvatarChange($parameters);
		if (!$parsed->isSuccess())
		{
			$this->addErrors($parsed->getErrors());

			return [];
		}
		$hasExpectedGeneration = array_key_exists('expectedSourceGenerationId', $parameters);
		$expectedGeneration = $parameters['expectedSourceGenerationId'] ?? null;
		if (is_string($expectedGeneration) && preg_match('/^(0|[1-9][0-9]*)$/D', $expectedGeneration))
		{
			$expectedGeneration = filter_var($expectedGeneration, FILTER_VALIDATE_INT);
		}
		if ($expectedGeneration !== null && (!is_int($expectedGeneration) || $expectedGeneration < 0))
		{
			$this->addError(new Error('Invalid source generation', GeneralSettingsPatch::ERROR_INVALID_PATCH));

			return [];
		}
		$requestedFields = is_array($parameters['mailbox'] ?? null) ? $parameters['mailbox'] : $parameters;
		if ($parameters !== [])
		{
			$dto = MailboxConnectDTO::createFromRequest(new Main\HttpRequest(
				$this->getRequest()->getServer(), [], $parameters, [], [],
			));
		}
		$result = $mailboxConnector->updateMailbox(
			$mailboxId,
			$dto,
			expectedSourceGenerationId: $expectedGeneration,
			hasExpectedSourceGenerationId: $hasExpectedGeneration,
			requestedFields: $parameters === [] ? null : $requestedFields,
			avatar: $parsed->getData()['change'] ?? null,
		);
		$this->addErrors($mailboxConnector->getErrors());

		return $result;
	}

	/**
	 * save the mass connection data entered by the user for later use in the connection history
	 *
	 * @param array $massConnectData
	 * @return array
	 */
	public function saveMassConnectDataAction(array $massConnectData): array
	{
		$mailMassConnectHelper = new MailMassConnect();
		$addResult = $mailMassConnectHelper->create($massConnectData, $this->getCurrentUser());

		if (!$addResult->isSuccess())
		{
			$this->addErrors($addResult->getErrors());

			return [];
		}

		return ['id' => $addResult->getId()];
	}

	/**
	 * @param MailboxConnectDTO $mailboxConnectDTO - mailbox connection data
	 * @param int $massConnectId - id of MailMassConnectTable entity
	 * @return array
	 *
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function connectMailboxFromMassconnectAction(MailboxConnectDTO $mailboxConnectDTO, int $massConnectId): array
	{
		if (!LicenseManager::isMailboxesMassConnectEnabled())
		{
			$this->addError(new Error('Mass mailbox connection is not allowed'));

			return [];
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if (!MailAccess::hasCurrentUserAccessToConnectMailboxToUser($mailboxConnectDTO->userIdToConnect))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION_TO_CONNECT_TO_USER')));

			return [];
		}

		$mailboxConnector = new MailboxConnector();
		$result = $mailboxConnector->connectMailboxFromMassconnect(
			$mailboxConnectDTO,
			$massConnectId,
			$currentUserId,
		);
		$this->addErrors($mailboxConnector->getErrors());

		return $result;
	}

	/**
	 * @param int[] $userIds
	 * @return array{items: array<int, array{userId: int, canConnectNew: bool}>, processedCount: int}
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function checkMailboxLimitsAction(array $userIds): array
	{
		if (!LicenseManager::isMailboxesMassConnectEnabled())
		{
			$this->addError(new Error('Mass mailbox connection is not allowed'));

			return [
				'items' => [],
				'processedCount' => 0,
			];
		}

		$maxUsersPerRequest = 500;
		if (count($userIds) > $maxUsersPerRequest)
		{
			$userIds = array_slice($userIds, 0, $maxUsersPerRequest);
		}

		return [
			'items' => MailboxConnector::getUsersCanConnectNewMailbox($userIds),
			'processedCount' => count($userIds),
		];
	}

	public function getDepartmentUsersAction(array $departmentIds): array
	{
		return NodeMemberService::getMembersByDepartmentIds($departmentIds);
	}

	public function getAvailableMailboxesAction(): array
	{
		$mailboxesFullData = MailboxTable::getUserMailboxes();
		$mailboxesCounters = Message::getCountersForUserMailboxes(
			Main\Engine\CurrentUser::get()->getId(),
		);
		$mailboxesProtectedData = [];

		$replaceWithTheCurrentUserName = Loader::includeModule('humanresources');

		$previousSeenMailboxId = (int)\CUserOptions::getOption('mail', 'previous_seen_mailbox_id', 0);
		$this->captureConversationOnboardingEligibility();
		$signaturesAvailable = Helper\Config\Feature::isMobileSignaturesAvailable();

		foreach ($mailboxesFullData as $mailbox)
		{
			$user = null;

			if ($replaceWithTheCurrentUserName)
			{
				$user = Container::getUserService()->getUserById((int)$mailbox['USER_ID']);
			}

			$mailboxId = (int)$mailbox['ID'];

				$mailboxesProtectedData[] = [
					'USERNAME' => $replaceWithTheCurrentUserName && $user ? Container::getUserService()->getUserName($user) : $mailbox['USERNAME'],
					'EMAIL' => $mailbox['EMAIL'],
					'NAME' => $mailbox['NAME'],
					'ID' => $mailboxId,
					'COUNTER' => $mailboxesCounters[$mailboxId]['UNSEEN'],
					'CAN_EDIT_SETTINGS' => MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId),
					'SIGNATURES_AVAILABLE' => $signaturesAvailable,
				];

			if ($previousSeenMailboxId === 0)
			{
				\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
			}
		}

		return $mailboxesProtectedData;
	}

	/**
	 * @return array{mailboxId: int, email: string}
	 */
	public function createPasswordlessRequestAction(MailboxConnectDTO $mailboxConnectDTO): array
	{
		if (!MailAccess::hasCurrentUserAccessToConnectMailboxToUser($mailboxConnectDTO->userIdToConnect))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION_TO_CONNECT_TO_USER')));

			return [];
		}

		$adminId = (int)CurrentUser::get()->getId();
		$passwordlessConnectHelper = new PasswordlessConnectHelper();
		$result = $passwordlessConnectHelper->createRequest($adminId, $mailboxConnectDTO->userIdToConnect, $mailboxConnectDTO);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	/**
	 * @return array{mailboxId: int, email: string}
	 */
	public function completePasswordlessRequestAction(int $mailboxId, string $password): array
	{
		$passwordlessConnectHelper = new PasswordlessConnectHelper();
		$result = $passwordlessConnectHelper->completeRequest($mailboxId, $password);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	public function cancelPasswordlessRequestAction(int $mailboxId): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$mailbox = MailboxTable::query()
			->setSelect(['USER_ID'])
			->where('ID', $mailboxId)
			->where('ACTIVE', MailboxStatus::Pending->value)
			->setLimit(1)
			->fetch()
		;

		if (!$mailbox)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_MAILBOX_NOT_FOUND')));

			return [];
		}

		$isOwner = $currentUserId === (int)$mailbox['USER_ID'];
		if (!$isOwner && !MailAccess::hasCurrentUserAccessToMassConnect())
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION')));

			return [];
		}

		$passwordlessConnectHelper = new PasswordlessConnectHelper();
		$result = $passwordlessConnectHelper->cancelRequest($mailboxId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [];
	}

	/**
	 * Validates IMAP/SMTP server availability without authentication (pre-send check for passwordless flow).
	 */
	public function validateConnectionSettingsAction(MailboxConnectDTO $dto): array
	{
		$helper = new PasswordlessConnectHelper();
		$result = $helper->validateConnectionSettings($dto);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return ['valid' => true];
	}

	/**
	 * @return array{mailboxId: int, email: string}|null
	 */
	public function getPendingPasswordlessRequestAction(): ?array
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		return (new PasswordlessConnectHelper())->getPendingRequestForUser($currentUserId);
	}

	public function resendPasswordlessRequestAction(int $mailboxId): array
	{
		$adminId = (int)CurrentUser::get()->getId();
		$helper = new PasswordlessConnectHelper();
		$result = $helper->resendRequest($mailboxId, $adminId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	public function deletePasswordlessRequestAction(int $mailboxId): array
	{
		$helper = new PasswordlessConnectHelper();
		$result = $helper->deleteRecord($mailboxId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [];
	}

	public function getPasswordlessRequestsCountAction(): array
	{
		$helper = new PasswordlessConnectHelper();
		$count = $helper->getSentRequestsTotalCount(['ACTIVE' => MailboxStatus::Pending->value]);

		return ['count' => $count];
	}

	private function getIds($ids, $ignoreOld = false): Main\Result
	{
		$result = new \Bitrix\Main\Result();

		if (empty($ids))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		$mailboxIds = $messIds = [];
		foreach ($ids as $id)
		{
			[$messId, $mailboxId] = explode('-', $id, 2);

			$mailboxIds[$mailboxId] = $mailboxId;
			$messIds[$messId] = $messId;
		}
		if (count($mailboxIds) > 1)
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		if($ignoreOld)
		{
			$oldIds = MailMessageUidTable::getList([
				'select' => ['ID'],
				'filter' => [
					'@ID' => $messIds,
					'=MAILBOX_ID' => current($mailboxIds),
					'=IS_OLD' => 'Y',
				],
			])->fetchAll();

			foreach ($oldIds as $item)
			{
				if(is_set($messIds[$item['ID']]))
				{
					unset($messIds[$item['ID']]);
				}
			}
		}

		if (empty($mailboxIds))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		if (empty($messIds))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		$result->setData([
			'mailboxId' => array_pop($mailboxIds),
			'messagesIds' => array_keys($messIds),
		]);

		return $result;
	}

	/**
	 * @throws Main\LoaderException
	 */
	public function syncMailboxAction(int $id, ?string $dir = null, bool $onlySyncCurrent = false): array
	{
		if (!MailboxAccess::hasCurrentUserAnyAccessToMailbox($id))
		{
			$this->addError(new Error('Access to the mailbox is denied', 403));

			return [];
		}

		$guard = (new MigrationActionGuard())->check($id);
		if (!$guard->isSuccess())
		{
			$this->errorCollection->add($guard->getErrors());

			return [];
		}

		$result = \Bitrix\Mail\Helper\Mailbox::quickSync($id, $dir, $onlySyncCurrent);
		$this->errorCollection = $result->getErrorCollection();

		return $result->getData();
	}

	/**
	 * @return array[]
	 * @throws Main\LoaderException
	 */
	public function syncAllUserMailboxesAction(): array
	{
		$userId = (int)Main\Engine\CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return ['mailboxes' => []];
		}

		$mailboxes = \Bitrix\Mail\MailboxTable::getUserMailboxes($userId);
		$mailboxIds = array_map(
			static fn (array $mailbox): int => (int)$mailbox['ID'],
			$mailboxes,
		);
		$guardResults = (new MigrationActionGuard())->checkMany($mailboxIds);

		$results = [];
		foreach ($mailboxes as $mailbox)
		{
			$id = (int)$mailbox['ID'];
			$guard = $guardResults[$id];
			if (!$guard->isSuccess())
			{
				$this->errorCollection->add($guard->getErrors());

				continue;
			}

			$result = \Bitrix\Mail\Helper\Mailbox::quickSync($id);
			$results[$id] = $result->getData();

			if (!$result->isSuccess())
			{
				$this->errorCollection->add($result->getErrors());
			}
		}

		return ['mailboxes' => $results];
	}

	public function getDefaultSettingsAction(): array
	{
		$mailboxConnector = new MailboxConnector();

		return [
			'defaultSenderName' => $mailboxConnector->getDefaultSenderName(),
			'currentUser' => $mailboxConnector->getCurrentUserData(),
		];
	}

	public function checkConnectMailboxAction(): array
	{
		$mailboxConnector = new MailboxConnector();
		$canConnect = $mailboxConnector->checkConnectMailbox();
		$this->addErrors($mailboxConnector->getErrors());

		return ['canConnect' => $canConnect];
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Controller\MailboxConnecting::checkConnectMailboxAction
	 */
	public function isMailboxConnectingAvailableAction(): bool
	{
		return \Bitrix\Mail\Helper\MailboxAccess::hasCurrentUserAccessToAddMailbox();
	}

	public function deleteMailboxAction(int $mailboxId): array
	{
		$result = MailboxConnector::deleteMailbox($mailboxId);
		$this->errorCollection = $result->getErrorCollection();

		return $result->getData();
	}

	/**
	 * @return array{applied: int[], skipped: array, failed: array}
	 */
	public function massDisconnectMailboxesAction(array $mailboxIds): array
	{
		if (empty($mailboxIds))
		{
			$this->addError(new Error('mailboxIds must not be empty', 422));

			return [];
		}

		if (!$this->checkBulkIdsLimit($mailboxIds))
		{
			return [];
		}

		$mailboxIds = $this->normalizeBulkIds($mailboxIds);

		$bulkResult = new BulkActionResult();

		$this->walkBulkBatch($mailboxIds, function () use ($mailboxIds, $bulkResult): void {
			foreach ($mailboxIds as $id)
			{
				if ($id <= 0)
				{
					$bulkResult->addSkipped($id, 'Invalid mailbox id');

					continue;
				}

				$result = MailboxConnector::deleteMailbox($id);

				if ($result->isSuccess())
				{
					$bulkResult->addApplied($id);
				}
				else
				{
					$this->addBulkFailure($bulkResult, $id, $result);
				}
			}
		});

		return $bulkResult->jsonSerialize();
	}

	private const BULK_ACTION_IDS_LIMIT = 500;

	private bool $bulkFailureLoggerResolved = false;
	private ?LoggerInterface $bulkFailureLogger = null;

	private function checkBulkIdsLimit(array $mailboxIds): bool
	{
		if (count($mailboxIds) > self::BULK_ACTION_IDS_LIMIT)
		{
			$this->addError(new Error(
				sprintf('mailboxIds count exceeds the limit of %d', self::BULK_ACTION_IDS_LIMIT),
				422,
			));

			return false;
		}

		return true;
	}

	/**
	 * An identifier is an integer or the decimal form a cast would give back unchanged. Everything the
	 * cast would rewrite - "123abc", "12 ", the padded "0012", a fraction, a boolean, an array - becomes
	 * 0 and is skipped by the pass, so no element reaches a mailbox it does not name. One such element
	 * costs itself and not the batch. The same identifier is walked once, so a batch of repetitions
	 * costs one transaction instead of one per repetition. The limit is asked of the raw batch before
	 * this, or padding with repetitions would walk around it.
	 *
	 * @return int[]
	 */
	private function normalizeBulkIds(array $mailboxIds): array
	{
		return array_values(array_unique(array_map(
			static function (mixed $rawId): int
			{
				if (is_int($rawId))
				{
					return $rawId;
				}

				return is_string($rawId) && (string)(int)$rawId === $rawId ? (int)$rawId : 0;
			},
			$mailboxIds,
		)));
	}

	/**
	 * The right to every mailbox is still asked separately; what the batch shares is the lookup
	 * behind the answer and the drop of the cache directories of the portal.
	 */
	private function walkBulkBatch(array $mailboxIds, callable $walk): void
	{
		$models = MailboxModel::createFromIds($mailboxIds);

		MailboxAccessController::getInstance((int)CurrentUser::get()->getId())->withMailboxBatch(
			$models,
			static fn () => MailboxTable::withDeferredCacheCleanup($walk),
		);
	}

	/**
	 * Only a mailbox held by a visible migration keeps a text of its own; every other refusal, a
	 * failed write included, answers the single phrase, so a missing mailbox and a foreign one
	 * answer alike. The machine reason of the refusal goes to the log, not to the answer.
	 */
	private function addBulkFailure(BulkActionResult $bulkResult, int $mailboxId, Main\Result $result): void
	{
		$blocked = $result->getErrorCollection()->getErrorByCode(MigrationActionGuard::ERROR_ACTION_BLOCKED);
		if ($blocked !== null)
		{
			$bulkResult->addFailed($mailboxId, $blocked->getMessage());

			return;
		}

		$errors = $result->getErrors();
		$reason = $errors === [] ? 'Unknown error' : $errors[0]->getMessage();

		$this->bulkFailureLogger()?->warning(
			'A mass mailbox action refused a mailbox of the batch.',
			[
				'mailboxId' => $mailboxId,
				'userId' => (int)CurrentUser::get()->getId(),
				'reason' => $reason,
			],
		);

		$bulkResult->addFailed(
			$mailboxId,
			(string)Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_MAILBOX_NOT_FOUND'),
		);
	}

	/** One logger for the whole batch: a batch of refusals would otherwise build one per mailbox. */
	private function bulkFailureLogger(): ?LoggerInterface
	{
		if (!$this->bulkFailureLoggerResolved)
		{
			$this->bulkFailureLoggerResolved = true;
			$this->bulkFailureLogger = (new Main\Diag\LoggerFactory())->createById('mail.MailboxBulkActions');
		}

		return $this->bulkFailureLogger;
	}

	/**
	 * @return array{applied: int[], skipped: array, failed: array}
	 */
	public function massSetCrmIntegrationAction(array $mailboxIds, $enabled, ?array $crmOptions = null): array
	{
		$enabled = $this->parseEnabledFlag($enabled);
		if ($enabled === null)
		{
			$this->addError(new Error('enabled must be a boolean flag', 422));

			return [];
		}

		if (empty($mailboxIds))
		{
			$this->addError(new Error('mailboxIds must not be empty', 422));

			return [];
		}

		if (!$this->checkBulkIdsLimit($mailboxIds))
		{
			return [];
		}

		$mailboxIds = $this->normalizeBulkIds($mailboxIds);

		$bulkResult = new BulkActionResult();

		$useDefaults = $enabled && $crmOptions === null;

		$resolvedCrmOptions = null;
		if (!$useDefaults)
		{
			if ($enabled)
			{
				$resolvedCrmOptions = \Bitrix\Mail\Helper\Dto\MailboxConnect\CrmOptions::fromArray(
					array_merge(['enabled' => true], $crmOptions),
				);
			}
			else
			{
				$resolvedCrmOptions = \Bitrix\Mail\Helper\Dto\MailboxConnect\CrmOptions::disabled();
			}
		}

		$crmWalk = function () use ($mailboxIds, $bulkResult, $useDefaults, $resolvedCrmOptions): void {
			foreach ($mailboxIds as $id)
			{
				if ($id <= 0)
				{
					$bulkResult->addSkipped($id, 'Invalid mailbox id');

					continue;
				}

				$mailboxConnector = new MailboxConnector();

				if ($useDefaults)
				{
					$result = $mailboxConnector->enableCrmWithDefaults($id);

					if (!$result->isSuccess())
					{
						$this->addBulkFailure($bulkResult, $id, $result);
					}
					elseif (($result->getData()['skipped'] ?? false) === true)
					{
						$bulkResult->addSkipped($id, (string)($result->getData()['reason'] ?? 'CRM already enabled'));
					}
					else
					{
						$bulkResult->addApplied($id);
					}
				}
				else
				{
					$result = $mailboxConnector->updateCrmOptions($id, $resolvedCrmOptions);

					if (!$result->isSuccess())
					{
						$this->addBulkFailure($bulkResult, $id, $result);
					}
					elseif (($result->getData()['skipped'] ?? false) === true)
					{
						$bulkResult->addSkipped($id, (string)($result->getData()['reason'] ?? 'No changes'));
					}
					else
					{
						$bulkResult->addApplied($id);
					}
				}
			}
		};

		$this->walkBulkBatch($mailboxIds, $crmWalk);

		return $bulkResult->jsonSerialize();
	}

	/**
	 * @return array{applied: int[], skipped: array, failed: array}
	 */
	public function massSetCalendarIntegrationAction(array $mailboxIds, $enabled): array
	{
		$enabled = $this->parseEnabledFlag($enabled);
		if ($enabled === null)
		{
			$this->addError(new Error('enabled must be a boolean flag', 422));

			return [];
		}

		if (empty($mailboxIds))
		{
			$this->addError(new Error('mailboxIds must not be empty', 422));

			return [];
		}

		if (!$this->checkBulkIdsLimit($mailboxIds))
		{
			return [];
		}

		$mailboxIds = $this->normalizeBulkIds($mailboxIds);

		$bulkResult = new BulkActionResult();

		$this->walkBulkBatch($mailboxIds, function () use ($mailboxIds, $bulkResult, $enabled): void {
			foreach ($mailboxIds as $id)
			{
				if ($id <= 0)
				{
					$bulkResult->addSkipped($id, 'Invalid mailbox id');

					continue;
				}

				$mailboxConnector = new MailboxConnector();
				$result = $mailboxConnector->updateCalendarOptions($id, $enabled);

				if (!$result->isSuccess())
				{
					$this->addBulkFailure($bulkResult, $id, $result);
				}
				elseif (($result->getData()['skipped'] ?? false) === true)
				{
					$bulkResult->addSkipped($id, (string)($result->getData()['reason'] ?? 'No changes'));
				}
				else
				{
					$bulkResult->addApplied($id);
				}
			}
		});

		return $bulkResult->jsonSerialize();
	}

	private function parseEnabledFlag(mixed $value): ?bool
	{
		return match ($value) {
			'Y' => true,
			'N' => false,
			default => null,
		};
	}
}
