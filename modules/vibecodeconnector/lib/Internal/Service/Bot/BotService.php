<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Bot;

use Bitrix\Im\Bot;
use Bitrix\Im\Command;
use Bitrix\Im\Model\BotTable;
use Bitrix\Im\RestBot;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Exception\BotOperationFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\BotWebhookGateway;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;

final class BotService
{
	public const VERSION = 2;
	public const BOT_CODE = 'vibecode_bot';

	private const MODULE_ID = 'vibecodeconnector';
	private const OPTION_BOT_ID = 'bot.botId';
	private const OPTION_CLIENT_ID = 'bot.clientId';
	private const OPTION_BOT_TOKEN = 'bot.botToken';
	private const OPTION_APPLICATION_TOKEN = 'bot.applicationToken';
	private const OPTION_WEBHOOK_PASSWORD_ID = 'bot.webhookPasswordId';

	private const AVATAR_PATH = '/bitrix/modules/vibecodeconnector/install/avatar/vibecode_bot/default.png';
	private const WEBHOOK_TITLE = 'Vibecode Bot Inbound';
	private const WEBHOOK_SCOPES = ['imbot'];
	private const OUTBOUND_WEBHOOK_PATH = '/api/portal-bot/webhook';
	private const INSTALL_LOCK_NAME = 'vibecodeconnector_bot_install';
	private const INSTALL_LOCK_TIMEOUT_SECONDS = 10;
	private array $webhookUrlCache = [];

	public function __construct(
		private readonly BaseEndpointProvider $endpointProvider,
		private readonly TokenGenerator $tokenGenerator,
		private readonly BotWebhookGateway $botWebhooks,
	)
	{
	}

	/**
	 * Removes the local bot and its inbound webhook. Pairing rows live in the
	 * pairing repository and are not touched here — they have their own lifecycle.
	 *
	 * Vibe-side bot cache is dropped as part of the pairing's `unregister`
	 * action (handled by `RegistrationService::unregister`), so this method
	 * doesn't talk to Vibe directly.
	 */
	public function uninstall(): void
	{
		if (!Loader::includeModule('im'))
		{
			throw new BotOperationFailedException('im module is not available', 'IM_MODULE_UNAVAILABLE');
		}

		$this->deleteWebhook();

		$rows = BotTable::getList([
			'filter' => [
				'=CODE' => self::BOT_CODE,
				'=MODULE_ID' => 'rest',
			],
			'select' => ['BOT_ID'],
		]);
		while ($row = $rows->fetch())
		{
			Bot::unRegister([
				'BOT_ID' => (int)$row['BOT_ID'],
				'MODULE_ID' => 'rest',
			]);
		}

		$this->clearOptions();
	}

	/**
	 * Cleans up legacy OAuth-style bots created by the previous registration scheme.
	 * Called by MigrationAgent once after module update. Idempotent.
	 *
	 * For each legacy bot we first tell the primary Vibe to drop its cache
	 * (best-effort: errors are swallowed since Vibe may already have purged it),
	 * then delete locally. The local delete always runs so a transient network
	 * failure does not leave orphaned bot rows on the portal.
	 *
	 * @param list<array{botId:int, appId:string, code?:string}> $legacyBots
	 */
	public function migrate(array $legacyBots): void
	{
		$client = new BotClient($this->endpointProvider);
		foreach ($legacyBots as $bot)
		{
			$botId = (int)($bot['botId'] ?? 0);
			$appId = (string)($bot['appId'] ?? '');

			try
			{
				$client->unregisterLegacy($botId, $appId);
			}
			catch (BotOperationFailedException $e)
			{
			}

			$this->deleteLegacyBot($botId);
		}
	}

	/**
	 * Idempotent local delete via the im public facade. Bot::unRegister returns false
	 * if the bot row is already gone, so this is safe to call after Vibe's own cleanup
	 * as a belt-and-suspenders guarantee against orphans. We pass only BOT_ID — no
	 * APP_ID ownership check needed since the detector (or our own option store) already
	 * verified the bot is ours, and a stale APP_ID would otherwise silently skip delete.
	 */
	private function deleteLegacyBot(int $botId): void
	{
		if ($botId <= 0)
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		Bot::unRegister([
			'BOT_ID' => $botId,
			'MODULE_ID' => 'rest',
		]);
	}

	public function isInstalled(): bool
	{
		return (string)Option::get(self::MODULE_ID, self::OPTION_BOT_ID, '') !== ''
			&& (string)Option::get(self::MODULE_ID, self::OPTION_CLIENT_ID, '') !== '';
	}

	/**
	 * Returns bot identity & secrets for Vibe to use as the lazy-pull source of truth.
	 *
	 * @return array{
	 *   version: int, botId: int, botCode: string, clientId: string,
	 *   botToken: string, applicationToken: string, webhookUrl: string
	 * }
	 */
	public function getBotInfo(): array
	{
		if (!$this->isInstalled())
		{
			$this->install();
		}

		$botId = (int)Option::get(self::MODULE_ID, self::OPTION_BOT_ID, 0);

		return [
			'version' => self::VERSION,
			'botId' => $botId,
			'botCode' => self::BOT_CODE,
			'botToken' => (string)Option::get(self::MODULE_ID, self::OPTION_BOT_TOKEN, ''),
			'clientId' => (string)Option::get(self::MODULE_ID, self::OPTION_CLIENT_ID, ''),
			'applicationToken' => (string)Option::get(self::MODULE_ID, self::OPTION_APPLICATION_TOKEN, ''),
			'webhookUrl' => $this->ensureWebhookUrl(),
		];
	}

	/**
	 * Serializes concurrent first-time bot provisioning via a portal-level MySQL
	 * named lock. Inside the lock we re-check `isInstalled()` — under load another
	 * worker may have just finished, in which case we silently return.
	 */
	private function install(): void
	{
		$connection = Application::getConnection();
		$acquired = $connection->lock(self::INSTALL_LOCK_NAME, self::INSTALL_LOCK_TIMEOUT_SECONDS);

		try
		{
			if ($this->isInstalled())
			{
				return;
			}

			if (!$acquired)
			{
				throw new BotOperationFailedException(
					'Bot install lock not acquired — another process is provisioning, retry shortly',
					'BOT_INSTALL_LOCK_TIMEOUT',
				);
			}

			$this->registerLocalBot();
		}
		finally
		{
			if ($acquired)
			{
				$connection->unlock(self::INSTALL_LOCK_NAME);
			}
		}
	}

	/**
	 * Re-binds the bot's outbound webhook to the current `BaseEndpointProvider`
	 * URL via the im public API. Intended to be called after the admin changes
	 * the active connection in module settings — keeps the bot's webhook in sync
	 * with the rest of the module's outbound flows (catalog, status checks, etc.)
	 * which all read the same base URL.
	 *
	 * No-op when the bot isn't installed (lazy-create path will use the new URL
	 * naturally when the bot is materialized).
	 */
	public function refreshOutboundWebhook(): void
	{
		if (!$this->isInstalled())
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$botId = (int)Option::get(self::MODULE_ID, self::OPTION_BOT_ID, 0);
		$botToken = (string)Option::get(self::MODULE_ID, self::OPTION_BOT_TOKEN, '');
		if ($botId <= 0 || $botToken === '')
		{
			return;
		}

		RestBot::update(
			['BOT_ID' => $botId, 'BOT_TOKEN' => $botToken],
			['WEBHOOK_URL' => $this->buildOutboundWebhookUrl()],
		);
	}

	/**
	 * Self-healing inbound-webhook resolver. If the stored system webhook was deleted
	 * (e.g. someone wiped it via direct DB) or deactivated, we transparently issue a
	 * new one for the bot user — so the next Vibe pull always gets a working endpoint
	 * without manual intervention.
	 */
	private function ensureWebhookUrl(): string
	{
		$passwordId = (int)Option::get(self::MODULE_ID, self::OPTION_WEBHOOK_PASSWORD_ID, 0);
		if ($passwordId > 0 && isset($this->webhookUrlCache[$passwordId]))
		{
			return $this->webhookUrlCache[$passwordId];
		}

		if (!Loader::includeModule('rest'))
		{
			throw new BotOperationFailedException('rest module is not available', 'REST_MODULE_UNAVAILABLE');
		}

		if ($passwordId > 0)
		{
			$webhook = $this->botWebhooks->find($passwordId);
			if ($webhook !== null && $webhook->isActive)
			{
				$url = $this->botWebhooks->buildUrl($webhook);
				$this->webhookUrlCache[$passwordId] = $url;

				return $url;
			}
		}

		return $this->createWebhook();
	}

	private function createWebhook(): string
	{
		$botId = (int)Option::get(self::MODULE_ID, self::OPTION_BOT_ID, 0);

		$webhook = $this->botWebhooks->issue(
			$botId,
			self::WEBHOOK_SCOPES,
			self::WEBHOOK_TITLE,
		);

		Option::set(self::MODULE_ID, self::OPTION_WEBHOOK_PASSWORD_ID, (string)$webhook->passwordId);

		$url = $this->botWebhooks->buildUrl($webhook);
		$this->webhookUrlCache = [$webhook->passwordId => $url];

		return $url;
	}

	private function deleteWebhook(): void
	{
		$this->webhookUrlCache = [];

		if (!Loader::includeModule('rest'))
		{
			return;
		}

		$passwordId = (int)Option::get(self::MODULE_ID, self::OPTION_WEBHOOK_PASSWORD_ID, 0);
		if ($passwordId > 0)
		{
			$this->botWebhooks->revoke($passwordId);
		}
	}

	private function registerLocalBot(): void
	{
		if (!Loader::includeModule('im'))
		{
			throw new BotOperationFailedException('im module is not available', 'IM_MODULE_UNAVAILABLE');
		}

		$this->cleanupOrphanBotRow();

		$botToken = $this->tokenGenerator->generateBotToken();
		$applicationToken = $this->tokenGenerator->generateApplicationToken();
		$webhookUrl = $this->buildOutboundWebhookUrl();

		$properties = [
			'NAME' => (string)Loc::getMessage('VIBECODECONNECTOR_BOT_SERVICE_USER_NAME'),
			'WORK_POSITION' => (string)Loc::getMessage('VIBECODECONNECTOR_BOT_SERVICE_USER_WORK_POSITION'),
		];

		$avatar = $this->loadAvatar();
		if ($avatar !== null)
		{
			$properties['PERSONAL_PHOTO'] = $avatar;
		}

		$botId = RestBot::register([
			'CODE' => self::BOT_CODE,
			'BOT_TOKEN' => $botToken,
			'WEBHOOK_URL' => $webhookUrl,
			'EVENT_MODE' => Bot::EVENT_MODE_WEBHOOK,
			'TYPE' => Bot::TYPE_SUPERVISOR,
			'PROPERTIES' => $properties,
		]);

		if (!$botId)
		{
			throw new BotOperationFailedException('RestBot::register returned false', 'REST_BOT_REGISTER_FAILED');
		}

		$clientId = Bot::WEBHOOK_CLIENT_ID_PREFIX . $botToken;

		Command::register([
			'BOT_ID' => $botId,
			'MODULE_ID' => 'rest',
			'APP_ID' => $clientId,
			'COMMAND' => '_action',
			'COMMON' => 'N',
			'HIDDEN' => 'Y',
		]);

		Option::set(self::MODULE_ID, self::OPTION_BOT_ID, (string)$botId);
		Option::set(self::MODULE_ID, self::OPTION_CLIENT_ID, $clientId);
		Option::set(self::MODULE_ID, self::OPTION_BOT_TOKEN, $botToken);
		Option::set(self::MODULE_ID, self::OPTION_APPLICATION_TOKEN, $applicationToken);

		$this->createWebhook();
	}

	private function buildOutboundWebhookUrl(): string
	{
		return rtrim($this->endpointProvider->getBaseUrl(), '/') . self::OUTBOUND_WEBHOOK_PATH;
	}

	/**
	 * Bot::register de-duplicates by (MODULE_ID, CODE), returning an existing BOT_ID
	 * and silently ignoring the new APP_ID. That leaves Options out of sync with b_im_bot.
	 * When an orphan row exists (admin cleared Options manually, uninstall failed mid-way, etc.)
	 * snip it first so the subsequent RestBot::register creates a fresh row with our new token.
	 */
	private function cleanupOrphanBotRow(): void
	{
		$existing = BotTable::getList([
			'filter' => [
				'=CODE' => self::BOT_CODE,
				'=MODULE_ID' => 'rest',
			],
			'select' => ['BOT_ID', 'APP_ID'],
			'limit' => 1,
		])->fetch();

		if (!$existing)
		{
			return;
		}

		RestBot::unRegister([
			'BOT_ID' => (int)$existing['BOT_ID'],
			'APP_ID' => (string)$existing['APP_ID'],
		]);
	}


	private function loadAvatar(): array|null
	{
		$absolutePath = Application::getDocumentRoot() . self::AVATAR_PATH;
		if (!File::isFileExists($absolutePath))
		{
			return null;
		}

		$fileArray = \CFile::makeFileArray($absolutePath);

		return is_array($fileArray) ? $fileArray : null;
	}

	private function clearOptions(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_BOT_ID]);
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_CLIENT_ID]);
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_BOT_TOKEN]);
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_APPLICATION_TOKEN]);
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_WEBHOOK_PASSWORD_ID]);
	}
}
