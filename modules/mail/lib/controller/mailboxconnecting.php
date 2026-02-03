<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxConnectDTO;
use Bitrix\Mail\Helper\Dto\MailboxConnect\MailboxMassconnectDTO;
use Bitrix\Mail\Helper\Mailbox\MailMassConnect;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
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

	public function getAutoWiredParameters(): array
	{
		return [
			new ValidationParameter(
				MailboxMassconnectDTO::class,
				fn() => MailboxMassconnectDTO::createFromRequest($this->getRequest()),
			),
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
		$result = $mailboxConnector->connectMailboxWithDefaultCrm($mailboxConnectDTO);
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
	 * @param MailboxMassconnectDTO $mailboxConnectDTO - mailbox connection data
	 * @param int $massConnectId - id of MailMassConnectTable entity
	 * @return array
	 *
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function connectMailboxFromMassconnectAction(MailboxMassconnectDTO $mailboxConnectDTO, int $massConnectId): array
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
				'COUNTER' => $mailboxesCounters[$mailboxId]['UNSEEN']
			];

			if ($previousSeenMailboxId === 0)
			{
				\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
			}
		}

		return $mailboxesProtectedData;
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

	public function isMailboxConnectingAvailableAction(): bool
	{
		return \Bitrix\Mail\Helper\MailboxAccess::hasCurrentUserAccessToAddMailbox();
	}

	public function deleteMailboxAction(int $mailboxId): array
	{
		$result = \Bitrix\Mail\Helper\Mailbox\MailboxConnector::deleteMailbox($mailboxId);
		$this->errorCollection = $result->getErrorCollection();

		return $result->getData();
	}
}
