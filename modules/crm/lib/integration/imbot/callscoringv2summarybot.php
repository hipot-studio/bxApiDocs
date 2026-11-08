<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Imbot;

use Bitrix\Crm\Copilot\CallAssessment\BiReportButton;
use Bitrix\Crm\Copilot\CallAssessment\Summary\SettingsRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Security\Role\Model\RolePermissionTable;
use Bitrix\Crm\Security\Role\Model\RoleRelationTable;
use Bitrix\Crm\Security\Role\RoleRelationHelper;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Im\Bot;
use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\Command;
use Bitrix\ImBot\Bot\Base;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAccessTable;
use Bitrix\Main\UserTable;

final class CallScoringV2SummaryBot extends Base
{
	public const BOT_CODE = 'crm_call_scoring_v2_summary';
	public const MODULE_ID = 'crm';

	public const COMMAND_MANAGER_SUMMARY = 'managerSummary';

	private const COMMAND_PARAM_MANAGER_ID = 'MANAGER_ID';
	private const COMMAND_PARAM_REFERENCE_DATE = 'AT';

	public static function register(array $params = []): int
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return 0;
		}

		$botId = self::getBotId();
		if ($botId > 0)
		{
			return $botId;
		}

		$registered = Bot::register([
			'CODE' => self::BOT_CODE,
			'TYPE' => Bot::TYPE_BOT,
			'MODULE_ID' => self::MODULE_ID,
			'CLASS' => self::class,
			'HIDDEN' => 'Y',
			'INSTALL_TYPE' => Bot::INSTALL_TYPE_SILENT,
			'METHOD_MESSAGE_ADD' => 'onMessageAdd',
			'METHOD_WELCOME_MESSAGE' => 'onChatStart',
			'METHOD_BOT_DELETE' => 'onBotDelete',
			'PROPERTIES' => [
				'NAME' => Loc::getMessage('CRM_CALL_SCORING_V2_SUMMARY_BOT_NAME'),
				'WORK_POSITION' => Loc::getMessage('CRM_CALL_SCORING_V2_SUMMARY_BOT_WORK_POSITION'),
				'COLOR' => 'AZURE',
				'PERSONAL_PHOTO' => self::uploadAvatar(),
			],
		]);

		if ($registered > 0)
		{
			self::setBotId($registered);
			self::registerCommands($registered);
			(new self())->seedRecipientsIfEmpty();

			return $registered;
		}

		return 0;
	}

	/**
	 * Registers the hidden keyboard command that backs the "manager summary" button.
	 * Idempotent: Command::register returns the existing id if the command is already there.
	 */
	public static function registerCommands(int $botId): void
	{
		if ($botId <= 0 || !Loader::includeModule('im'))
		{
			return;
		}

		Command::register([
			'MODULE_ID' => self::MODULE_ID,
			'BOT_ID' => $botId,
			'COMMAND' => self::COMMAND_MANAGER_SUMMARY,
			'CLASS' => self::class,
			'METHOD_COMMAND_ADD' => 'onCommandAdd',
			'HIDDEN' => 'Y',
			'COMMON' => 'Y',
		]);
	}

	/**
	 * Keyboard-command handler for the "manager summary" button.
	 * Static bool contract (@see \Bitrix\ImBot\Bot\Base); refusals and errors are a silent no-op
	 * returning true - never throws out to the command dispatcher.
	 */
	public static function onCommandAdd($messageId, $messageFields): bool
	{
		try
		{
			if (
				($messageFields['COMMAND'] ?? '') !== self::COMMAND_MANAGER_SUMMARY
				|| ($messageFields['COMMAND_CONTEXT'] ?? '') !== 'KEYBOARD'
			)
			{
				return true;
			}

			$commandParams = (string)($messageFields['COMMAND_PARAMS'] ?? '');
			$managerId = self::extractManagerId($commandParams);
			$referenceDate = self::extractReferenceDate($commandParams);
			$fromUserId = (int)($messageFields['FROM_USER_ID'] ?? 0);
			if ($managerId <= 0 || $fromUserId <= 0)
			{
				return true;
			}

			$bot = new self();
			if (!$bot->isAllowedUserId($fromUserId))
			{
				return true;
			}

			// The launcher owns the dedup/cache decision. Announce "being prepared" only when a
			// new generation actually starts (jobId set). A cached re-delivery (success, jobId 0)
			// and a repeated click on a fresh PENDING stay silent; any other launch failure surfaces
			// an error so the click never leaves the user without feedback.
			$result = AIManager::launchGenerateManagerSummary($managerId, $fromUserId, $referenceDate);
			if ($result->isSuccess())
			{
				if ((int)$result->getJobId() > 0)
				{
					$bot->notify($fromUserId, Loc::getMessage('CRM_CALL_SCORING_V2_SUMMARY_BOT_MANAGER_SUMMARY_PENDING'));
				}
				// isSuccess && jobId == 0: a cached summary was already delivered upstream - stay silent.
			}
			elseif (!self::isManagerSummaryAlreadyRunning($result))
			{
				$bot->notifyManagerSummaryError($fromUserId);
			}
		}
		catch (\Throwable $e)
		{
			AIManager::logger()->error(
				'{date}: {class}: onCommandAdd failed: {error}' . PHP_EOL,
				['class' => self::class, 'error' => $e->getMessage()],
			);
		}

		return true;
	}

	private static function extractManagerId(string $commandParams): int
	{
		if (preg_match('/' . self::COMMAND_PARAM_MANAGER_ID . ':(\d+)/i', $commandParams, $matches))
		{
			return (int)$matches[1];
		}

		return 0;
	}

	/**
	 * Reference date carried by the button (unix seconds of the moment the message was shown).
	 * Absent on messages built before this parameter existed - callers fall back to "now".
	 */
	private static function extractReferenceDate(string $commandParams): ?DateTime
	{
		if (preg_match('/' . self::COMMAND_PARAM_REFERENCE_DATE . ':(\d+)/i', $commandParams, $matches))
		{
			return DateTime::createFromTimestamp((int)$matches[1]);
		}

		return null;
	}

	/**
	 * A fresh PENDING generation reported by the launcher (Q-3): the "being prepared" message
	 * was already shown on the first click, so a repeated click stays a silent no-op instead
	 * of surfacing an error to the user.
	 */
	private static function isManagerSummaryAlreadyRunning(\Bitrix\Crm\Integration\AI\Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			if ($error->getCode() === \Bitrix\Crm\Integration\AI\ErrorCode::JOB_ALREADY_EXISTS)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Delivers the generated manager summary to the recipient dialog. Membership is re-checked
	 * so a recipient removed between click and delivery gets nothing.
	 */
	public function deliverManagerSummary(int $userId, string $message): ?int
	{
		return $this->notify($userId, $message);
	}

	public function notifyManagerSummaryError(int $userId): ?int
	{
		return $this->notify($userId, Loc::getMessage('CRM_CALL_SCORING_V2_SUMMARY_BOT_MANAGER_SUMMARY_ERROR'));
	}

	public static function unRegister(): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$botId = self::getBotId();
		if ($botId <= 0)
		{
			return true;
		}

		return Bot::unRegister([
			'BOT_ID'    => $botId,
			'MODULE_ID' => self::MODULE_ID,
		]);
	}

	/**
	 * Bot avatar shipped with the module. Public and matching the base signature
	 * (@see \Bitrix\ImBot\Bot\Base::uploadAvatar); $lang is unused - a single asset for all locales.
	 *
	 * @return array|false CFile array for PERSONAL_PHOTO, or false when the asset is missing.
	 */
	public static function uploadAvatar($lang = LANGUAGE_ID)
	{
		$avatarPath = Application::getDocumentRoot()
			. '/bitrix/modules/' . self::MODULE_ID . '/install/avatar/' . self::BOT_CODE . '/default.webp';

		return \CFile::makeFileArray($avatarPath);
	}

	public static function onChatStart($dialogId, $joinFields): bool
	{
		return true;
	}

	public static function onMessageAdd($messageId, $messageFields): bool
	{
		return true;
	}

	/** @var int[]|null memoized per instance to avoid re-reading the option in tight loops */
	private ?array $allowedUserIdsCache = null;

	/**
	 * @return int[]
	 */
	public function getAllowedUserIds(): array
	{
		return $this->allowedUserIdsCache ??= (new SettingsRepository())->load()->recipientUserIds;
	}

	public function isAllowedUserId(int $userId): bool
	{
		return in_array($userId, $this->getAllowedUserIds(), true);
	}

	/**
	 * Contract: after ensureRegistered() > 0 the `im` module is guaranteed loaded.
	 */
	public function ensureRegistered(): int
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			return 0;
		}

		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		$botId = self::getBotId();
		if ($botId > 0)
		{
			return $botId;
		}

		return self::register();
	}

	public function notify(int $userId, string $message, ?Keyboard $keyboard = null): ?int
	{
		if ($userId <= 0 || $message === '')
		{
			return null;
		}

		$botId = $this->ensureRegistered();
		if ($botId <= 0)
		{
			return null;
		}

		if (!$this->isAllowedUserId($userId))
		{
			return null;
		}

		return $this->sendOne($botId, $userId, $message, $keyboard);
	}

	public function notifyManager(int $managerUserId, string $message, ?Keyboard $keyboard = null): ?int
	{
		if ($managerUserId <= 0 || $message === '')
		{
			return null;
		}

		$botId = $this->ensureRegistered();
		if ($botId <= 0)
		{
			return null;
		}

		return $this->sendOne($botId, $managerUserId, $message, $keyboard);
	}

	/**
	 * @param int[] $excludeUserIds recipients to skip; values coerced to int, duplicates and order ignored
	 */
	public function broadcast(string $message, ?Keyboard $keyboard = null, array $excludeUserIds = []): int
	{
		$botId = $this->ensureRegistered();
		if ($botId <= 0)
		{
			return 0;
		}

		$allowedUserIds = $this->getAllowedUserIds();
		if (empty($allowedUserIds) || $message === '')
		{
			return 0;
		}

		$recipients = self::filterRecipients($allowedUserIds, $excludeUserIds);

		$sent = 0;
		foreach ($recipients as $userId)
		{
			if ($this->sendOne($botId, $userId, $message, $keyboard) !== null)
			{
				$sent++;
			}
		}

		return $sent;
	}

	/**
	 * @param int[] $allowedUserIds
	 * @param int[] $excludeUserIds recipients to skip; values coerced to int, duplicates and order ignored
	 * @return int[] allowed recipients without the excluded ones, keys reset to a plain list
	 */
	private static function filterRecipients(array $allowedUserIds, array $excludeUserIds): array
	{
		$excluded = array_flip(array_map('intval', $excludeUserIds));

		return array_values(array_filter(
			$allowedUserIds,
			static fn(int $userId) => !isset($excluded[$userId]),
		));
	}

	private function sendOne(int $botId, int $userId, string $message, ?Keyboard $keyboard): ?int
	{
		$fields = [
			'DIALOG_ID' => (string)$userId,
			'MESSAGE'   => $message,
		];
		if ($keyboard !== null)
		{
			$fields['KEYBOARD'] = $keyboard;
		}

		$result = Bot::addMessage(
			['BOT_ID' => $botId, 'MODULE_ID' => self::MODULE_ID],
			$fields,
		);

		return is_int($result) && $result > 0 ? $result : null;
	}

	public function buildDefaultKeyboard(): ?Keyboard
	{
		$botId = $this->ensureRegistered();
		if ($botId <= 0)
		{
			return null;
		}

		$keyboard = new Keyboard($botId);

		$dashboardUrl = BiReportButton::getInstance()->getUrl('menu_crm');
		if ($dashboardUrl !== null)
		{
			self::addLineButton($keyboard, 'CRM_CALL_SCORING_V2_SUMMARY_BOT_BTN_OPEN_DASHBOARD', $dashboardUrl);
		}

		self::addLineButton($keyboard, 'CRM_CALL_SCORING_V2_SUMMARY_BOT_BTN_CONFIGURE', '/crm/copilot-call-assessment/summary/');

		return $keyboard;
	}

	public function buildSituationKeyboard(int $managerUserId): ?Keyboard
	{
		$botId = $this->ensureRegistered();
		if ($botId <= 0)
		{
			return null;
		}

		$keyboard = new Keyboard($botId);

		self::addLineButton($keyboard, 'CRM_CALL_SCORING_V2_SUMMARY_BOT_BTN_SITUATION_OPEN_CALLS', '/crm/copilot-call-assessment/');

		if ($managerUserId > 0)
		{
			// The reference date pins the 7-day window to the moment the button was shown, so a
			// click days later still summarizes the period the recipient saw, not "now minus 7 days".
			self::addCommandButton(
				$keyboard,
				'CRM_CALL_SCORING_V2_SUMMARY_BOT_BTN_SITUATION_MANAGER_SUMMARY',
				self::COMMAND_MANAGER_SUMMARY,
				self::COMMAND_PARAM_MANAGER_ID . ':' . $managerUserId
					. ';' . self::COMMAND_PARAM_REFERENCE_DATE . ':' . (new DateTime())->getTimestamp(),
			);
			self::addLineButton($keyboard, 'CRM_CALL_SCORING_V2_SUMMARY_BOT_BTN_SITUATION_OPEN_CHAT', '/online/?IM_DIALOG=' . $managerUserId);
		}
		else
		{
			self::addLineButton($keyboard, 'CRM_CALL_SCORING_V2_SUMMARY_BOT_BTN_SITUATION_MANAGER_SUMMARY', '/crm/copilot-call-assessment/summary/');
		}

		return $keyboard;
	}

	private static function addLineButton(Keyboard $keyboard, string $textKey, string $link): void
	{
		$keyboard->addButton([
			'TEXT' => Loc::getMessage($textKey),
			'LINK' => $link,
			'DISPLAY' => 'LINE',
			'BG_COLOR_TOKEN' => 'ai-assistant',
		]);
	}

	private static function addCommandButton(
		Keyboard $keyboard,
		string $textKey,
		string $command,
		string $commandParams,
	): void
	{
		$keyboard->addButton([
			'TEXT' => Loc::getMessage($textKey),
			'COMMAND' => $command,
			'COMMAND_PARAMS' => $commandParams,
			'DISPLAY' => 'LINE',
			'BG_COLOR_TOKEN' => 'ai-assistant',
		]);
	}

	private function seedRecipientsIfEmpty(): void
	{
		$repo = new SettingsRepository();
		if ($repo->isInitialized())
		{
			return;
		}

		$settings = $repo->load()->withRecipientUserIds($this->resolveCrmAdminUserIds());
		$repo->save($settings);
	}

	/**
	 * @return int[]
	 */
	private function resolveCrmAdminUserIds(): array
	{
		$roleIds = array_unique(array_column(
			RolePermissionTable::getList([
				'select' => ['ROLE_ID'],
				'filter' => [
					'=ENTITY' => 'CONFIG',
					'=PERM_TYPE' => UserPermissions::OPERATION_UPDATE,
					'=ATTR' => UserPermissions::PERMISSION_CONFIG,
				],
				'cache' => ['ttl' => 86400 /* 1d */],
			])->fetchAll(),
			'ROLE_ID',
		));

		$codes = [];
		if (!empty($roleIds))
		{
			$codes = array_unique(array_column(
				RoleRelationTable::query()
					->setSelect(['RELATION'])
					->whereIn('ROLE_ID', $roleIds)
					->setCacheTtl(86400 /* 1d */)
					->fetchAll(),
				'RELATION',
			));
		}

		$adminGroupCode = (new RoleRelationHelper())->getAdminGroupRelationAccessCode();
		if ($adminGroupCode !== '')
		{
			$codes[] = $adminGroupCode;
		}

		$codes = array_values(array_unique(array_filter($codes)));
		if (empty($codes))
		{
			return [];
		}

		$userIds = array_values(array_filter(array_unique(array_map(
			static fn(array $r) => (int)$r['USER_ID'],
			UserAccessTable::query()
				->setSelect(['USER_ID'])
				->whereIn('ACCESS_CODE', $codes)
				->whereNot('PROVIDER_ID', 'imchat')
				->setGroup(['USER_ID'])
				->fetchAll(),
		)), static fn(int $v) => $v > 0));

		if ($userIds === [])
		{
			return [];
		}

		return array_values(array_map(
			static fn(array $r) => (int)$r['ID'],
			UserTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $userIds)
				->where('ACTIVE', 'Y')
				->fetchAll(),
		));
	}
}
