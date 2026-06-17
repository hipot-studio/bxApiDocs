<?php

namespace Bitrix\Socialservices\OAuth;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\JWT;

final class StateService
{
	private const SEPARATOR = '_';
	private const STORAGE_PREFIX = 'StateService';
	private static self $instance;
	private SessionLocalStorage $storage;

	public function __construct()
	{
		$this->storage = Application::getInstance()->getLocalSession(self::STORAGE_PREFIX);
	}

	private function saveState(string $state, array $payload): void
	{
		$this->storage->set($state, $payload);
	}

	#region public api

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Declares SITE_ID and/or ADMIN_SECTION when state matches StateService token.
	 *
	 * WARNING: This method MUST BE WORKED without core and prolog includes.
	 */
	public static function stateRequestProcessing(): void
	{
		$state = (string)($_REQUEST['state'] ?? '');
		if (empty($state))
		{
			return;
		}

		$parts = explode(self::SEPARATOR, $state, 4);
		$partsCount = count($parts);
		if ($partsCount !== 3 && $partsCount !== 4)
		{
			return;
		}

		if (!defined('ADMIN_SECTION') && defined('SOCSERV_CHECK_STATE_ADMIN_SECTION') && $parts[1] === '1')
		{
			define('ADMIN_SECTION', true);
		}

		if (!defined('SITE_ID') && preg_match('/^[a-z0-9_]{2}$/i', $parts[0], $m))
		{
			define('SITE_ID', $m[0]);
		}
	}

	public function createState(
		array $payload,
		bool $appendTimestamp = true,
		Context $context = null,
		?array $additionalInfo = null,
	): string
	{
		$context ??= Context::getCurrent();

		$value = Json::encode($payload);
		if ($appendTimestamp)
		{
			$value .= time();
		}

		$encodedAdditionalInfo = null;
		if ($additionalInfo !== null)
		{
			$encodedAdditionalInfo = Json::encode($additionalInfo);
			$value .= $encodedAdditionalInfo;
		}

		$state = join(self::SEPARATOR, [
			$payload['site_id'] ?? $context->getSite() ?? 's1',
			$context->getRequest()->isAdminSection() ? 1 : 0,
			hash('sha224', $value),
		]);

		if ($encodedAdditionalInfo !== null)
		{
			$state .= self::SEPARATOR . JWT::urlsafeB64Encode($encodedAdditionalInfo);
		}

		$this->saveState($state, $payload);

		return $state;
	}

	public function getPayload(string $state): ?array
	{
		$payload = $this->storage->get($state);
		if (is_array($payload))
		{
			return $payload;
		}

		return null;
	}

	#endregion public api
}
