<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarChange;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarService;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Dto\MailboxConnect\GeneralSettingsPatch;
use Bitrix\Mail\Helper\Enum\CrmFlag;
use Bitrix\Mail\Helper\Enum\CrmImapFilterContext;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Mailbox\CrmImapFilter;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MailboxSearchIndexHelper;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Internal\Service\SenderCacheCleaner;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\Internals\VacationAutoReplyTable;
use Bitrix\Mail\Service\VacationAutoReply\VacationAutoReplyService;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Result;

Loc::loadMessages(dirname(__DIR__, 3) . '/helper/mailbox/mailboxconnector.php');

final class GeneralSettingsService
{
	public function __construct(
		private readonly SettingsWriteCoordinator $coordinator = new SettingsWriteCoordinator(),
		private readonly bool $allowCanceled = false,
	)
	{
	}

	public function update(int $mailboxId, GeneralSettingsPatch $patch, ?AvatarChange $avatar = null): Result
	{
		$mailbox = MailboxTable::getByPrimary($mailboxId, [
			'select' => ['ID', 'USER_ID', 'ACTIVE', 'SERVER_TYPE'],
		])->fetch();
		$access = $this->checkAccess($mailbox ?: [], $patch);
		if (!$access->isSuccess())
		{
			return $access;
		}
		$validation = $this->validatePatch($mailboxId, $patch);
		if (!$validation->isSuccess())
		{
			return $validation;
		}
		if ($patch->isEmpty() && $avatar === null)
		{
			return (new Result())->setData(['id' => $mailboxId]);
		}

		$save = function (?int $resourceId = null) use ($mailboxId, $patch, $avatar): Result {
			return $this->coordinator->execute(
				$mailboxId,
				function (array $state, array &$afterCommit) use ($patch, $avatar, $resourceId): Result {
					$mailbox = $state['mailbox'];
					$avatarService = new AvatarService();
					if ($avatar !== null)
					{
						$checked = $avatarService->checkChange($mailbox, $avatar);
						if (!$checked->isSuccess())
						{
							return $checked;
						}
					}
					$result = $this->applyWithinTransaction($mailbox, $patch, $afterCommit);
					if (!$result->isSuccess() || $avatar === null)
					{
						return $result;
					}
					$applied = $avatarService->applyWithinTransaction($mailbox, $avatar, $resourceId, $afterCommit);

					return $applied->isSuccess()
						? $result->setData($result->getData() + $applied->getData())
						: $applied;
				},
			);
		};

		return $avatar === null ? $save() : (new AvatarService())->save($mailboxId, $avatar, $save);
	}

	/** The caller owns the mailbox lock, rollback and publication after the outer commit. */
	public function applyWithinTransaction(array $mailbox, GeneralSettingsPatch $patch, array &$afterCommit): Result
	{
		$mailboxId = (int)$mailbox['ID'];
		$access = $this->checkAccess($mailbox, $patch);
		if (!$access->isSuccess())
		{
			return $access;
		}
		$validation = $this->validatePatch($mailboxId, $patch);
		if (!$validation->isSuccess())
		{
			return $validation;
		}
		if ($patch->isEmpty())
		{
			return (new Result())->setData(['id' => $mailboxId]);
		}

		$connector = new MailboxConnector();
		if (
			$patch->has('shareAccess')
			&& !$connector->assertSharedMailboxLimit($patch->get('shareAccess'), (int)$mailbox['USER_ID'], $mailboxId)
		)
		{
			return (new Result())->addErrors($connector->getErrors());
		}

		$options = $mailbox['OPTIONS'];
		$fields = [];
		foreach (['mailboxName' => 'NAME', 'link' => 'LINK', 'senderName' => 'USERNAME'] as $key => $field)
		{
			if ($patch->has($key))
			{
				$fields[$field] = $patch->get($key);
			}
		}
		if ($patch->has('senderName'))
		{
			$options['name'] = $patch->get('senderName');
			if (is_array($options['passwordless_smtp'] ?? null))
			{
				$options['passwordless_smtp']['senderName'] = $patch->get('senderName');
			}
		}
		if ($patch->has('useSenderName'))
		{
			$options['useSenderName'] = $patch->get('useSenderName');
			if (is_array($options['passwordless_smtp'] ?? null))
			{
				$options['passwordless_smtp']['useSenderName'] = $patch->get('useSenderName');
			}
		}
		if ($patch->has('iCalAccess'))
		{
			$options['ical_access'] = $patch->get('iCalAccess') ? 'Y' : 'N';
		}
		if ($patch->hasCrmChanges())
		{
			$this->applyCrmPatch($patch, $mailbox, $options, $fields);
		}
		if ($options !== $mailbox['OPTIONS'])
		{
			$fields['OPTIONS'] = MailboxTable::getEntity()->getField('OPTIONS')
				->modifyValueBeforeSave($options, ['OPTIONS' => $options])
			;
		}

		$senders = ($patch->has('senderName') || $patch->has('useSenderName'))
			? $this->coordinator->lockSendersWithinTransaction($mailboxId, $mailbox)
			: [];
		$legacySender = MailboxConnector::getMailboxSender(
			$mailboxId,
			(string)$mailbox['EMAIL'],
			(int)$mailbox['USER_ID'],
			readOnly: true,
			lockedSenders: $senders,
		);
		$senders = array_filter($senders, static fn (array $sender): bool => ($sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] === $mailboxId)
			|| (int)$sender['ID'] === (int)($legacySender['ID'] ?? 0));
		$this->writeFields(MailboxTable::getTableName(), $mailboxId, $fields);
		if (isset($fields['PERIOD_CHECK']))
		{
			$this->rebaseVacationInterval($mailboxId, (int)$fields['PERIOD_CHECK']);
		}
		foreach ($senders as $sender)
		{
			$senderId = (int)$sender['ID'];
			$senderFields = [];
			if ($patch->has('senderName'))
			{
				$senderFields['NAME'] = $patch->get('senderName');
			}
			if ($patch->has('useSenderName'))
			{
				// Preserve the stored SMTP credentials without decrypting or re-encrypting them.
				$storedOptions = SenderTable::getEntity()->getField('OPTIONS')
					->convertValueFromDb($sender['STORED_OPTIONS'])
				;
				$senderOptions = $storedOptions === null || $storedOptions === ''
					? []
					: unserialize((string)$storedOptions, ['allowed_classes' => false]);
				if (!is_array($senderOptions))
				{
					throw new \UnexpectedValueException('The sender options are unreadable');
				}
				$senderOptions['useSenderName'] = $patch->get('useSenderName');
				$senderFields['OPTIONS'] = serialize($senderOptions);
			}
			$this->writeFields(SenderTable::getTableName(), $senderId, $senderFields);
			$afterCommit[] = static function () use ($senderId, $sender, $mailboxId): void {
				(new SenderCacheCleaner())->clearForSender(
					$senderId,
					[(string)$sender['EMAIL']],
					[
						['parentModuleId' => 'mail', 'parentId' => $mailboxId],
						[
							'parentModuleId' => (string)$sender['PARENT_MODULE_ID'],
							'parentId' => (int)($sender['PARENT_ID'] ?? 0),
						],
					],
				);
			};
		}

		$accessState = null;
		if ($patch->has('shareAccess'))
		{
			$accessState = $connector->applyMailboxShareAccess(
				$patch->get('shareAccess'),
				(int)$mailbox['USER_ID'],
				$mailboxId,
				deferEffects: true,
			);
			if ($accessState === null)
			{
				return (new Result())->addErrors($connector->getErrors());
			}
		}
		if ($patch->hasCrmChanges())
		{
			$crmEnabled = in_array(CrmFlag::Connect->value, $options['flags'] ?? [], true);
			$crmResult = CrmImapFilter::sync(
				$mailboxId,
				$crmEnabled,
				CrmImapFilterContext::SettingsSave,
				lockFilters: true,
			);
			if (!$crmResult->isSuccess())
			{
				return $crmResult;
			}
			$markerResult = (new CrmImapGateReconciler())->writeSatisfiedMarkerWithinTransaction(
				$mailboxId,
				$crmEnabled,
			);
			if (!$markerResult->isSuccess())
			{
				return $markerResult;
			}
		}

		$this->scheduleEffects($mailbox, $fields, $accessState, $connector, $afterCommit);

		return (new Result())->setData(['id' => $mailboxId]);
	}

	public function validatePatch(int $mailboxId, GeneralSettingsPatch $patch): Result
	{
		if ($mailboxId <= 0)
		{
			return (new Result())->addError(new Error('Invalid mailbox ID', GeneralSettingsPatch::ERROR_INVALID_PATCH));
		}
		$result = $patch->validate();
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === GeneralSettingsPatch::ERROR_UNSUPPORTED_FIELD)
			{
				$guard = (new MigrationActionGuard())->check($mailboxId);
				if (!$guard->isSuccess())
				{
					return $guard->getErrorCollection()->getErrorByCode(MigrationActionGuard::ERROR_ACTION_BLOCKED)
						? $this->error('MAIL_MIGRATION_CREDENTIALS_LOCKED', MigrationActionGuard::ERROR_ACTION_BLOCKED)
						: $guard;
				}

				break;
			}
		}
		if (!$result->isSuccess())
		{
			return $result;
		}
		if ($patch->has('mailboxName') && $patch->get('mailboxName') === '')
		{
			$result->addError(new Error(
				(string)Loc::getMessage('MAIL_MAILBOX_CONNECTOR_CLIENT_FORM_ERROR'),
				GeneralSettingsPatch::ERROR_INVALID_PATCH,
				['field' => 'mailboxName'],
			));
		}
		if ($patch->has('senderName') && $patch->get('senderName') !== '')
		{
			foreach (Sender::checkSenderNameCharacters($patch->get('senderName'))->getErrors() as $error)
			{
				$result->addError(new Error($error->getMessage(), MailboxConnector::SMTP_SENDER_ERROR_KEY));
			}
		}
		foreach ($patch->get('crmOptions.config.crm_new_lead_for') ?? [] as $email)
		{
			if (!(new Address($email, ['checkingPunycode' => true]))->validate())
			{
				$result->addError(new Error('Invalid CRM email address', GeneralSettingsPatch::ERROR_INVALID_PATCH));

				break;
			}
		}

		return $result;
	}

	public function checkAccess(array $mailbox, GeneralSettingsPatch $patch): Result
	{
		if (
			$mailbox === []
			|| (!in_array($mailbox['ACTIVE'], [MailboxStatus::Active->value, MailboxStatus::Pending->value], true)
				&& !($this->allowCanceled && $mailbox['ACTIVE'] === MailboxStatus::Canceled->value))
			|| $mailbox['SERVER_TYPE'] !== 'imap'
		)
		{
			return $this->error('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_NO_MAILBOX');
		}
		$mailboxId = (int)$mailbox['ID'];
		$ownerId = (int)$mailbox['USER_ID'];
		$userId = (int)CurrentUser::get()->getId();
		$canEdit = Feature::isMailboxGridAvailable()
			? MailboxModel::createFromId($mailboxId)->getOwnerId() === $ownerId
				&& MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId)
			: $userId > 0 && ($userId === $ownerId || MailboxAccess::hasCurrentUserAdminAccess());
		if (!$canEdit)
		{
			return $this->error('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED');
		}
		if ($patch->has('shareAccess') && !MailboxAccess::hasCurrentUserAccessToEditMailboxAccess($mailboxId, $ownerId))
		{
			return $this->error('MAIL_MAILBOX_CONNECTOR_REMOVE_DELETE_ERROR_DENIED');
		}
		if (
			$patch->hasCrmChanges()
			&& (!Loader::includeModule('crm')
				|| !MailboxAccess::hasCurrentUserAccessToEditMailboxIntegrationCrm()
				|| !Feature::isCrmAvailable())
		)
		{
			return $this->error('MAIL_MAILBOX_CONNECTOR_CRM_ACCESS_DENIED', MailboxConnector::CRM_ACCESS_DENIED_ERROR_KEY);
		}
		if ($patch->get('iCalAccess') === true && !Loader::includeModule('calendar'))
		{
			return (new Result())->addError(new Error('Calendar module is not available'));
		}

		return new Result();
	}

	private function applyCrmPatch(GeneralSettingsPatch $patch, array $mailbox, array &$options, array &$fields): void
	{
		$crm = $patch->mergeCrmOptions($options);
		$flags = (array)($options['flags'] ?? []);
		$wasPublic = in_array(CrmFlag::PublicBind->value, $flags, true);
		$config = $patch->get('crmOptions.config') ?? [];
		foreach ($config as $key => $value)
		{
			$options[$key] = $value;
		}
		if (array_key_exists('crm_lead_resp', $config))
		{
			$options['crm_lead_resp'] = array_values(array_unique($crm->leadResp ?: [(int)$mailbox['USER_ID']]));
		}
		if (array_key_exists('crm_new_lead_for', $config))
		{
			$options['crm_new_lead_for'] = array_values(array_unique(array_map(
				static fn (string $email): string => (new Address($email, ['checkingPunycode' => true]))->getEmail(),
				$crm->newLeadFor,
			)));
		}
		if ($patch->has('crmOptions.enabled'))
		{
			$this->setFlag($flags, CrmFlag::Connect, $crm->enabled);
			$this->setFlag($flags, CrmFlag::Preconnect, false);
		}
		if ($patch->has('crmOptions.enabled') || array_key_exists('crm_public', $config))
		{
			$this->setFlag($flags, CrmFlag::PublicBind, $crm->enabled && $crm->public);
		}
		if (array_key_exists('crm_vcf', $config))
		{
			$this->setFlag($flags, CrmFlag::DenyNewContact, !$crm->vcf);
		}
		if (array_key_exists('crm_new_entity_in', $config) || array_key_exists('crm_new_entity_out', $config))
		{
			$this->setFlag($flags, CrmFlag::DenyNewLead, false);
			$this->setFlag($flags, CrmFlag::DenyEntityIn, $crm->newEntityIn === null);
			$this->setFlag($flags, CrmFlag::DenyEntityOut, $crm->newEntityOut === null);
		}
		$options['flags'] = $flags;
		if (array_key_exists('crm_sync_days', $config))
		{
			$days = $crm->syncDays ?? MailboxConnector::CRM_MAX_AGE;
			if ($days < 0)
			{
				unset($options['crm_sync_from']);
			}
			else
			{
				$options['crm_sync_from'] = strtotime(sprintf('-%u days', $days));
			}
		}
		$isPublic = in_array(CrmFlag::PublicBind->value, $flags, true);
		if ($wasPublic !== $isPublic)
		{
			$fields['PERIOD_CHECK'] = $isPublic
				? ((int)Option::get('mail', 'public_mailbox_sync_interval', 0) ?: 10)
				: 60 * 24;
		}
	}

	private function setFlag(array &$flags, CrmFlag $flag, bool $enabled): void
	{
		$flags = array_values(array_filter($flags, static fn (mixed $value): bool => $value !== $flag->value));
		if ($enabled)
		{
			$flags[] = $flag->value;
		}
	}

	private function writeFields(string $table, int $id, array $fields): void
	{
		if ($fields === [])
		{
			return;
		}
		$connection = Application::getConnection();
		[$update, $binds] = $connection->getSqlHelper()->prepareUpdate($table, $fields);
		$connection->queryExecute(sprintf('UPDATE %s SET %s WHERE ID = %u', $table, $update, $id), $binds);
	}

	private function rebaseVacationInterval(int $mailboxId, int $interval): void
	{
		$connection = Application::getConnection();
		$table = VacationAutoReplyTable::getTableName();
		if (!$connection->isTableExists($table))
		{
			return;
		}
		$columns = $connection->getTableFields($table);
		if (!isset($columns['ORIGINAL_PERIOD_CHECK'], $columns['SHORTENED_PERIOD_CHECK']))
		{
			return;
		}

		(new VacationAutoReplyService())->rebaseRememberedInterval($mailboxId, $interval);
	}

	private function scheduleEffects(
		array $mailbox,
		array $fields,
		?array $accessState,
		MailboxConnector $connector,
		array &$afterCommit,
	): void
	{
		$mailboxId = (int)$mailbox['ID'];
		$ownerId = (int)$mailbox['USER_ID'];
		$afterCommit[] = static function () use ($mailboxId, $ownerId, $accessState): void {
			MailboxTable::cleanRuntimeFullRowCachesByMailboxId($mailboxId);
			if ($accessState !== null)
			{
				MailboxTable::cleanCachesByMailboxId($mailboxId, $ownerId);
			}
			MailboxTable::cleanEntityCache();
		};
		if ($accessState !== null)
		{
			$afterCommit[] = static fn () => $connector->detachUsersLosingShareAccess(
				$mailboxId,
				$accessState['previousAccess'],
				$accessState['currentAccess'],
				refreshAccess: true,
			);
			$userId = (int)CurrentUser::get()->getId();
			$afterCommit[] = static fn () => Notification::dispatchAccessChangedNotifications(
				$mailboxId,
				(string)$mailbox['EMAIL'],
				$accessState['previousAccess'],
				$accessState['currentAccess'],
				$userId,
			);
		}
		if (isset($fields['PERIOD_CHECK']))
		{
			$afterCommit[] = function () use ($mailboxId): void {
				$this->coordinator->execute($mailboxId, static function (array $state): Result {
					$current = $state['mailbox'];
					$agent = sprintf('Bitrix\\Mail\\Helper::syncMailboxAgent(%u);', (int)$current['ID']);
					\CAgent::removeAgent($agent, 'mail');
					\CAgent::addAgent($agent, 'mail', 'N', (int)$current['PERIOD_CHECK'] * 60);

					return new Result();
				});
			};
		}
		if (isset($fields['NAME']) && $fields['NAME'] !== $mailbox['NAME'])
		{
			$afterCommit[] = static fn () => MailboxSearchIndexHelper::saveSearchIndexForMailbox($mailboxId);
		}
	}

	private function error(string $messageId, string $code = MailboxConnector::STANDARD_ERROR_KEY): Result
	{
		return (new Result())->addError(new Error((string)Loc::getMessage($messageId), $code));
	}
}
