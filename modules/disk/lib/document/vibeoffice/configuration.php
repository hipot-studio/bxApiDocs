<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Config\Feature\VibeofficeFlag;
use Bitrix\Disk\Driver;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\File;
use Bitrix\Main\Web\Json;

final class Configuration
{
	public const DEFAULT_MAX_FILESIZE = 104857600;
	public const PREVIOUS_CREDENTIALS_TTL = 900;

	private const MANAGED_OPTION = 'disk_vibeoffice_managed';
	private const CREDENTIAL_KEYS = [
		'server' => 'server',
		'key_id' => 'keyId',
		'api_secret' => 'apiSecret',
		'webhook_secret' => 'webhookSecret',
	];
	private const STATE_KEYS = ['hostId', 'domain', 'keyConfirmed', 'activated', 'documentServerHost'];

	/**
	 * Ceilings of the ALG-RETRY settings. The open cycle waits inside the synchronous request the
	 * editor page is rendered by, so a value written by mistake - 5000 meant as milliseconds, seconds
	 * on the wire - must cost a bounded wait instead of a worker held for the whole of it. A value
	 * above the ceiling is brought down to it, not thrown away.
	 *
	 * The ceilings stand at twice the canonical defaults of ADR (a budget of 15 seconds, three
	 * attempts, a pause of up to 5): enough room for an experiment on a stand, still far below the
	 * execution time of a request and the rate limit of the platform. The reserve for the worst answer
	 * and the threshold of a long attempt are measured against the budget, so they share its ceiling -
	 * above it neither of them means anything the cycle could act on.
	 */
	private const MAX_OPEN_BUDGET_SEC = 30;
	private const MAX_OPEN_ATTEMPTS = 5;
	private const MAX_OPEN_WORST_ANSWER_SEC = self::MAX_OPEN_BUDGET_SEC;
	private const MAX_OPEN_LONG_ATTEMPT_SEC = self::MAX_OPEN_BUDGET_SEC;
	private const MAX_OPEN_PAUSE_SEC = 10;

	/** @var array|null override values from bitrix/php_interface/disk-vibeoffice.php */
	private ?array $localValues = null;

	public function __construct()
	{
		$this->loadLocalValues();
	}

	/**
	 * Turns the portal flag off so that new document opens no longer route to vibeoffice.
	 *
	 * This is the first, mandatory step of the rollback drain ({@see \Bitrix\Disk\Document\Vibeoffice\DrainService},
	 * ALG-DRAIN): once cleared, {@see VibeofficeHandler::isEnabled()} returns false and the
	 * dispatch intercept stops forwarding to this handler, while drain still finishes the
	 * already-open sessions.
	 *
	 * @throws ConfigurationException when the value is not stored or a rule of the registry keeps VibeOffice on.
	 */
	public function disableOnPortal(): void
	{
		$this->switchOnPortal(false);
	}

	/**
	 * Enables Vibeoffice for new document opens on the portal.
	 *
	 * @throws ConfigurationException when the value of the flag is not stored. While the legacy option is honoured,
	 * a rule of the registry cannot keep VibeOffice off after this call.
	 */
	public function enableOnPortal(): void
	{
		$this->switchOnPortal(true);
	}

	/** Returns connection metadata without credential material. */
	public function getManagedState(): ?array
	{
		$managed = $this->getManagedValues();
		if ($managed === null)
		{
			return null;
		}

		$state = array_intersect_key($managed, array_flip(self::STATE_KEYS));
		$state['previousUntil'] = $this->getPreviousCredentials($managed)['expiresAt'] ?? null;

		return $state;
	}

	/** Updates metadata under the administrator operation lock, preserving credentials. */
	public function saveManagedState(array $patch): void
	{
		$managed = $this->getManagedValues() ?? [];
		$managed = array_replace($managed, array_intersect_key($patch, array_flip(self::STATE_KEYS)));
		$managed['previous'] = $this->getPreviousCredentials($managed);
		$this->storeManagedValues($managed);
	}

	/** Publishes credentials and supplied metadata together under the administrator operation lock. */
	public function replaceManagedCredentials(
		array $credentials, bool $keepPrevious, array $state = [], bool $preserveWebhookSecret = false,
	): array
	{
		[$managed, $current] = $this->getPublicationSource();
		$replacement = [];
		foreach (self::CREDENTIAL_KEYS as $key => $managedKey)
		{
			$value = $preserveWebhookSecret && $managedKey === 'webhookSecret'
				? $current[$managedKey] : ($credentials[$managedKey] ?? null)
			;
			if (!is_string($value) || $value === '')
			{
				throw new ArgumentException('Incomplete Vibeoffice credentials.', 'credentials');
			}
			$replacement[$managedKey] = $value;
		}

		$managed = array_replace($managed, array_intersect_key($state, array_flip(self::STATE_KEYS)));
		$managed['previous'] = null;
		if ($keepPrevious && !in_array(null, $current, true) && !in_array('', $current, true))
		{
			$managed['previous'] = [
				'keyId' => $current['keyId'],
				'apiSecret' => $current['apiSecret'],
				'webhookSecret' => $current['webhookSecret'],
				'expiresAt' => time() + self::PREVIOUS_CREDENTIALS_TTL,
			];
		}
		$managed['credentials'] = $replacement;
		$this->storeManagedValues($managed);

		return $managed;
	}

	/** Returns the previous API secret while its acceptance window is open. */
	public function getPreviousApiSecret(): ?string
	{
		return $this->getPreviousCredentials($this->getManagedValues() ?? [])['apiSecret'] ?? null;
	}

	/** Returns the previous webhook secret while its acceptance window is open. */
	public function getPreviousWebhookSecret(): ?string
	{
		return $this->getPreviousCredentials($this->getManagedValues() ?? [])['webhookSecret'] ?? null;
	}

	/** Detects file overrides even when their values are empty or null. */
	public function hasLocalCredentialOverride(): bool
	{
		return array_intersect_key($this->localValues ?? [], self::CREDENTIAL_KEYS) !== [];
	}

	/** Detects credential overrides on every portal site before publishing global options. */
	public function hasSiteCredentialOverride(): bool
	{
		$sites = \Bitrix\Main\SiteTable::query()->setSelect(['LID'])->exec();
		while ($site = $sites->fetch())
		{
			foreach (array_keys(self::CREDENTIAL_KEYS) as $key)
			{
				if (Option::getRealValue('disk', 'disk_vibeoffice_' . $key, $site['LID']) !== null)
				{
					return true;
				}
			}
		}

		return false;
	}

	/** Checks whether all four effective credential values are present. */
	public function hasReadableCredentials(): bool
	{
		foreach (array_keys(self::CREDENTIAL_KEYS) as $key)
		{
			$value = $this->getValue($key, 'disk_vibeoffice_' . $key);
			if ($value === null || $value === '')
			{
				return false;
			}
		}

		return true;
	}

	public function isStableDocumentKeyEnabled(): bool
	{
		return in_array(
			$this->getValue('stable_document_key_enabled', 'disk_vibeoffice_stable_document_key_enabled'),
			['Y', '1'],
			true,
		);
	}

	/**
	 * Raw `client_origin` of the php_interface override, never the module option: the origin of the
	 * portal page is set by the administrator of the server only. A value of another type is absent.
	 */
	public function getClientOrigin(): ?string
	{
		$value = $this->getLocalValues('client_origin');

		return is_string($value) ? $value : null;
	}

	/**
	 * Base URL of the vibeoffice editing platform.
	 */
	public function getServer(): ?string
	{
		return $this->getValue('server', 'disk_vibeoffice_server');
	}

	/**
	 * Key identifier (X-VO-Key header value, e.g. vok_...).
	 */
	public function getKeyId(): ?string
	{
		return $this->getValue('key_id', 'disk_vibeoffice_key_id');
	}

	/**
	 * Secret used to sign outgoing protocol-client requests.
	 * Backend-only: never returned to the browser / document service.
	 */
	public function getApiSecret(): ?string
	{
		return $this->getValue('api_secret', 'disk_vibeoffice_api_secret');
	}

	/**
	 * Secret used to verify incoming webhooks.
	 */
	public function getWebhookSecret(): ?string
	{
		return $this->getValue('webhook_secret', 'disk_vibeoffice_webhook_secret');
	}

	/**
	 * Workgroup (project) ids the vibeoffice engine is narrowed to.
	 *
	 * The php_interface override (`enabled_group_ids`, an int[]) wins over the module option
	 * `disk_vibeoffice_enabled_groups` (a CSV string). Empty / non-numeric / non-positive entries
	 * are dropped and the result is a de-duplicated int[]. An empty array means no narrowing — the
	 * feature stays portal-wide (current behaviour).
	 *
	 * @return int[]
	 */
	public function getEnabledGroupIds(): array
	{
		$local = $this->getLocalValues('enabled_group_ids');
		if (is_array($local))
		{
			return $this->normalizeGroupIds($local);
		}

		$csv = (string)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_vibeoffice_enabled_groups', '');

		return $this->normalizeGroupIds(explode(',', $csv));
	}

	/**
	 * @param array $values
	 * @return int[]
	 */
	private function normalizeGroupIds(array $values): array
	{
		$ids = [];
		foreach ($values as $value)
		{
			if (is_string($value))
			{
				$value = trim($value);
			}
			if (!is_numeric($value))
			{
				continue;
			}
			$id = (int)$value;
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/**
	 * Wall-clock budget of the whole open cycle, in seconds (ALG-RETRY `budgetSec`).
	 *
	 * Read once per open cycle by {@see OpenRetryPolicy::createFromConfiguration()}, never per attempt.
	 */
	public function getOpenBudgetSeconds(): int
	{
		return $this->getIntValue(
			'open_budget_sec',
			'disk_vibeoffice_open_budget_sec',
			OpenRetryPolicy::DEFAULT_BUDGET_SEC,
			self::MAX_OPEN_BUDGET_SEC,
		);
	}

	/**
	 * Cap on the number of `open` attempts of one cycle, the first one included (ALG-RETRY `maxAttempts`).
	 */
	public function getOpenMaxAttempts(): int
	{
		return $this->getIntValue(
			'open_max_attempts',
			'disk_vibeoffice_open_max_attempts',
			OpenRetryPolicy::DEFAULT_MAX_ATTEMPTS,
			self::MAX_OPEN_ATTEMPTS,
		);
	}

	/**
	 * Reserve for the worst platform answer, in seconds (ALG-RETRY `worstAnswerSec`): a new attempt
	 * starts only while the budget still holds a pause plus this reserve.
	 */
	public function getOpenWorstAnswerSeconds(): int
	{
		return $this->getIntValue(
			'open_worst_answer_sec',
			'disk_vibeoffice_open_worst_answer_sec',
			OpenRetryPolicy::DEFAULT_WORST_ANSWER_SEC,
			self::MAX_OPEN_WORST_ANSWER_SEC,
		);
	}

	/**
	 * Threshold telling a long failed attempt from an instant one, in seconds (ALG-RETRY `longAttemptSec`).
	 */
	public function getOpenLongAttemptSeconds(): int
	{
		return $this->getIntValue(
			'open_long_attempt_sec',
			'disk_vibeoffice_open_long_attempt_sec',
			OpenRetryPolicy::DEFAULT_LONG_ATTEMPT_SEC,
			self::MAX_OPEN_LONG_ATTEMPT_SEC,
		);
	}

	/**
	 * Cap on the pause the platform asks for in `Retry-After`, in seconds (ALG-RETRY `maxPauseSec`).
	 */
	public function getOpenMaxPauseSeconds(): int
	{
		return $this->getIntValue(
			'open_max_pause_sec',
			'disk_vibeoffice_open_max_pause_sec',
			OpenRetryPolicy::DEFAULT_MAX_PAUSE_SEC,
			self::MAX_OPEN_PAUSE_SEC,
		);
	}

	public function getMaxFileSize(): int
	{
		$value = $this->getValue('max_filesize', 'disk_vibeoffice_max_filesize');

		if ($value === null || $value === '')
		{
			return self::DEFAULT_MAX_FILESIZE;
		}

		return (int)$value;
	}

	/**
	 * Resolves file overrides, managed credentials and legacy options in that order.
	 */
	private function getValue(string $key, string $optionName): ?string
	{
		$local = $this->getLocalValues($key);
		if ($local !== null)
		{
			return (string)$local;
		}
		$managedKey = self::CREDENTIAL_KEYS[$key] ?? null;
		if ($managedKey !== null)
		{
			$managed = $this->getManagedValues();
			$value = $managed['credentials'][$managedKey] ?? null;
			if (is_string($value))
			{
				return $value;
			}
		}

		return Option::get(Driver::INTERNAL_MODULE_ID, $optionName, null);
	}

	/**
	 * Stores the value of {@see VibeofficeFlag}, then mirrors it to the legacy option the portal still honours and
	 * checks the result the portal gets. A value of the flag that is not stored leaves the option as it was. A value
	 * that is stored but overruled by a rule stays as the intent of the registry: the rules are not touched here.
	 */
	private function switchOnPortal(bool $enabled): void
	{
		Config\Feature::clearCache();
		if ($enabled)
		{
			Config\Feature::enable(VibeofficeFlag::class);
		}
		else
		{
			Config\Feature::disable(VibeofficeFlag::class);
		}
		Config\Feature::clearCache();

		$stored = Config\Feature\FeatureFlagTable::getByPrimary(VibeofficeFlag::class, ['select' => ['ENABLED']])
			->fetchObject()
			?->getEnabled()
		;
		if ($stored === $enabled)
		{
			Option::set(Driver::INTERNAL_MODULE_ID, Feature::LEGACY_OPTION, $enabled ? 'Y' : 'N');
		}
		if ($stored !== $enabled || Feature::isEnabledOnPortal() !== $enabled)
		{
			throw new ConfigurationException(
				'VibeOffice is not ' . ($enabled ? 'enabled' : 'disabled') . ' on the portal by the feature registry.',
			);
		}
	}

	private function getManagedValues(): ?array
	{
		$value = Option::get(Driver::INTERNAL_MODULE_ID, self::MANAGED_OPTION, '');
		if ($value === '')
		{
			return null;
		}
		try
		{
			$managed = Json::decode($value);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($managed) && $managed !== [] ? $managed : null;
	}

	private function getPreviousCredentials(array $managed): ?array
	{
		$previous = $managed['previous'] ?? null;
		if (!is_array($previous) || (int)($previous['expiresAt'] ?? 0) <= time())
		{
			return null;
		}
		foreach (['keyId', 'apiSecret', 'webhookSecret'] as $key)
		{
			if (!is_string($previous[$key] ?? null) || $previous[$key] === '')
			{
				return null;
			}
		}

		return $previous;
	}

	private function getPublicationSource(): array
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$names = [self::MANAGED_OPTION];
			foreach (array_keys(self::CREDENTIAL_KEYS) as $key)
			{
				$names[] = 'disk_vibeoffice_' . $key;
			}
			$query = new SqlExpression('SELECT NAME, VALUE FROM b_option WHERE MODULE_ID = ?s AND NAME IN (?@)',
				'disk', $names);
			$values = array_column(Application::getConnection()->query($query->compile())->fetchAll(), 'VALUE', 'NAME');
			$site = Application::getInstance()->getContext()?->getSite();
			if (is_string($site) && $site !== '')
			{
				$query = new SqlExpression(
					'SELECT NAME, VALUE FROM b_option_site WHERE MODULE_ID = ?s AND SITE_ID = ?s AND NAME IN (?@)',
					'disk', $site, $names,
				);
				try
				{
					$values = array_replace($values, array_column(
						Application::getConnection()->query($query->compile())->fetchAll(), 'VALUE', 'NAME',
					));
				}
				catch (\Bitrix\Main\DB\SqlQueryException)
				{
				}
			}
			$managed = [];
			try
			{
				$value = Json::decode($values[self::MANAGED_OPTION] ?? 'null');
				$managed = is_array($value) ? $value : [];
			}
			catch (ArgumentException)
			{
			}
			$current = [];
			foreach (self::CREDENTIAL_KEYS as $key => $managedKey)
			{
				$local = $this->getLocalValues($key);
				$stored = $managed['credentials'][$managedKey] ?? null;
				$current[$managedKey] = $local !== null ? (string)$local
					: (is_string($stored) ? $stored : ($values['disk_vibeoffice_' . $key] ?? null));
			}

			return [$managed, $current];
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function storeManagedValues(array $managed): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, self::MANAGED_OPTION, Json::encode($managed));
	}

	/**
	 * Resolves a positive integer value within the range the cycle can work by: a missing, empty or
	 * non-numeric one yields the default, one above the ceiling is brought down to the ceiling.
	 */
	private function getIntValue(string $key, string $optionName, int $default, int $max): int
	{
		$value = $this->getValue($key, $optionName);

		if ($value === null || !is_numeric($value))
		{
			return $default;
		}

		$value = (int)$value;

		return $value > 0 ? min($value, $max) : $default;
	}

	private function getLocalValues(string $key): mixed
	{
		return $this->localValues[$key] ?? null;
	}

	private function loadLocalValues(): void
	{
		$path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/disk-vibeoffice.php';
		if (File::isFileExists($path))
		{
			$localValues = require($path);
			if (is_array($localValues))
			{
				$this->localValues = $localValues;
			}
		}
	}
}
