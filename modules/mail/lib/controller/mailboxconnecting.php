<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Controller\ActionFilter\ConnectionRequestResponsibleAdminAccess;
use Bitrix\Mail\Controller\ActionFilter\MassConnectAccess;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\Mailbox\MailMassConnect;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Helper\Mailbox\MailboxSettingsConfig;
use Bitrix\Mail\Helper\Mailbox\PasswordlessConnectHelper;
use Bitrix\Mail\Internals\MailboxConnectionRequestTable;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\OAuth;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailServicesTable;
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
use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\HumanResources\Service\Container;

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

		return [
			'createPasswordlessRequest' => $massConnectFilter,
			'validateConnectionSettings' => $massConnectFilter,
			'resendPasswordlessRequest' => $massConnectFilter,
			'deletePasswordlessRequest' => $massConnectFilter,
			'getPasswordlessRequestsCount' => $massConnectFilter,
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
	 * @return string
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getUrlOauthAction(string $serviceName, string $type = OAuth::WEB_TYPE): string
	{
		$oauthHelper = OAuth::getInstance($serviceName);

		if (!$oauthHelper)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_MAIL_MODULE_OAUTH_SERVICE_IS_NOT_CONFIGURED')));

			return false;
		}

		return $oauthHelper->getUrl($type);
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
		if (!MailboxAccess::hasCurrentUserAccessToAddMailbox())
		{
			$this->addError(new Error('Mailbox connection is not allowed'));

			return [];
		}

		$mailboxConnector = new MailboxConnector();
		$result = $mailboxConnectDTO->crmOptions !== null
			? $mailboxConnector->connectMailboxWithCustomCrm($mailboxConnectDTO)
			: $mailboxConnector->connectMailboxWithDefaultCrm($mailboxConnectDTO)
		;
		$this->addErrors($mailboxConnector->getErrors());

		$mailboxId = (int)($result['id'] ?? 0);

		if ($mailboxId > 0)
		{
			if (empty($this->getErrors()))
			{
				\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
			}
		}

		return $result;
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

		if ($request['STATUS'] !== MailboxConnectionRequestTable::STATUS_PENDING)
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

	public function getSettingsConfigAction(): array
	{
		return MailboxSettingsConfig::getClientConfig();
	}

	public function updateMailboxAction(int $mailboxId, MailboxConnectDTO $dto): array
	{
		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_MAILBOX_CONNECTING_ERROR_HAS_NOT_PERMISSION')));

			return [];
		}

		$mailboxConnector = new MailboxConnector();
		$result = $mailboxConnector->updateMailbox($mailboxId, $dto);
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
			$oldIds = MailMessageUidTable::getList(array(
				'select' => array('ID'),
				'filter' => array(
					'@ID' => $messIds,
					'=MAILBOX_ID' => current($mailboxIds),
					'=IS_OLD' => 'Y',
				),
			))->fetchAll();

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

		$results = [];
		foreach (\Bitrix\Mail\MailboxTable::getUserMailboxes($userId) as $mailbox)
		{
			$id = (int)$mailbox['ID'];
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
}
