<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Seo;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Binding;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Crm\V2\Internal\Service\Tracking\SourceCatalog;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Seo\Analytics\Account as SeoAnalyticsAccount;
use Bitrix\Seo\Analytics\Service;
use Bitrix\Seo\Retargeting\AuthAdapter;

class TrackingAdsGateway
{
	private const ACCOUNT_CACHE_TTL = 60;
	private const ACCOUNT_CACHE_DIR = 'crm/tracking/ad/accounts';
	private const ACCOUNT_LIST_BUDGET_SECONDS = 20;

	public function provider(string $code): string
	{
		return match ($code) {
			'yandex', 'google', 'instagram', 'vkads' => $code,
			'fb' => 'facebook',
			'vk' => 'vkontakte',
			default => throw Refusal::ProviderUnsupported->exception(),
		};
	}

	public function assertAuthorizationAvailable(Binding $binding): void
	{
		$this->assertAvailable($binding);
	}

	public function authorize(Binding $binding): string
	{
		$this->assertAvailable($binding);
		try
		{
			$url = AuthAdapter::create($binding->provider, new Service())->getAuthUrl();
		}
		catch (\Throwable)
		{
			throw Refusal::ProviderUnavailable->exception();
		}
		if (!is_string($url) || $url === '')
		{
			throw Refusal::ProviderUnavailable->exception();
		}

		return $url;
	}

	/** @return list<array{provider: string, clientId: string, accountId: ?string,
	 * name: string, capabilities: array, isConnected: bool}> */
	public function list(Binding $binding): array
	{
		$provider = $this->providerData($binding);
		$hasAccounts = (bool)$provider['HAS_ACCOUNTS'];
		$capabilities = $this->capabilities($binding->provider);
		$rows = [];
		$deadline = microtime(true) + self::ACCOUNT_LIST_BUDGET_SECONDS;
		foreach ($provider['CLIENTS'] ?? [] as $client)
		{
			$clientId = (string)($client['CLIENT_ID'] ?? '');
			if ($clientId === '')
			{
				continue;
			}
			if (!$hasAccounts)
			{
				$rows[] = $this->accountRow($binding, $clientId, null,
					(string)(($client['NAME'] ?? null) ?: $clientId), $capabilities);
				continue;
			}
			foreach ($this->accounts($binding->provider, $clientId, $deadline) as $account)
			{
				$accountId = (string)($account['id'] ?? '');
				if ($accountId !== '')
				{
					$rows[] = $this->accountRow($binding, $clientId, $accountId,
						(string)(($account['name'] ?? null) ?: $accountId), $capabilities);
				}
			}
		}

		return $rows;
	}

	public function validateAccount(Binding $binding, string $clientId, ?string $accountId): void
	{
		$this->assertAvailable($binding);
		try
		{
			$service = new Service();
			$service->setClientId($clientId);
			$adapter = AuthAdapter::create($binding->provider, $service);
			$clients = $adapter->getAuthorizedClientsList();
		}
		catch (\Throwable)
		{
			throw Refusal::ProviderUnavailable->exception();
		}
		$found = false;
		foreach ($clients as $client)
		{
			if ((string)($client['proxy_client_id'] ?? '') === $clientId)
			{
				$found = true;
				break;
			}
		}
		if (!$found)
		{
			throw Refusal::AccountUnavailable->exception();
		}
		try
		{
			$account = SeoAnalyticsAccount::create($binding->provider, null, $service);
			$account->getRequest()->setAuthAdapter($adapter);
			if (!$account->getProfileCached())
			{
				$adapter->removeAuth();
				throw Refusal::AccountUnavailable->exception();
			}
		}
		catch (\DomainException $exception)
		{
			throw $exception;
		}
		catch (\Throwable)
		{
			throw Refusal::ProviderUnavailable->exception();
		}
		if (!$account->hasAccounts())
		{
			if ($accountId !== null)
			{
				throw Refusal::AccountUnavailable->exception();
			}
			return;
		}
		if ($accountId === null)
		{
			throw Refusal::AccountUnavailable->exception();
		}
		foreach ($this->accounts($binding->provider, $clientId) as $account)
		{
			if ((string)($account['id'] ?? '') === $accountId)
			{
				return;
			}
		}
		throw Refusal::AccountUnavailable->exception();
	}

	protected function canUseSeo(): bool
	{
		return Loader::includeModule('seo') && Loader::includeModule('socialservices');
	}

	private function assertAvailable(Binding $binding): void
	{
		if (!$this->canUseSeo())
		{
			throw Refusal::SeoUnavailable->exception();
		}
		if ((new SourceCatalog())->find($binding->code) === null)
		{
			throw Refusal::ProviderUnavailable->exception();
		}
	}

	private function providerData(Binding $binding): array
	{
		$this->assertAvailable($binding);
		$service = Service::getInstance();
		$previousClientId = $service->getClientId();
		try
		{
			$service->setClientId('');
			$provider = Service::getProviders([$binding->provider])[$binding->provider] ?? null;
		}
		catch (\Throwable)
		{
			throw Refusal::ProviderUnavailable->exception();
		}
		finally
		{
			$service->setClientId($previousClientId);
		}
		if (!is_array($provider))
		{
			throw Refusal::ProviderUnavailable->exception();
		}

		return $provider;
	}

	private function accounts(string $provider, string $clientId, ?float $deadline = null): array
	{
		$service = Service::getInstance();
		try
		{
			$client = AuthAdapter::create($provider, $service)->getClientById($clientId);
		}
		catch (\Throwable)
		{
			throw Refusal::AccountUnavailable->exception();
		}
		$token = (string)($client['access_token'] ?? '');
		if ($token === '')
		{
			throw Refusal::AccountUnavailable->exception();
		}
		$cacheId = hash('sha256', $provider . "\0" . $clientId . "\0" . hash('sha256', $token));
		$cache = Cache::createInstance();
		if ($cache->initCache(self::ACCOUNT_CACHE_TTL, $cacheId, self::ACCOUNT_CACHE_DIR))
		{
			return $cache->getVars();
		}
		if ($deadline !== null && microtime(true) >= $deadline)
		{
			throw Refusal::RemoteTimeout->exception();
		}
		$previousClientId = $service->getClientId();
		try
		{
			$service->setClientId($clientId);
			Service::resetErrors();
			$accounts = Service::getAccounts($provider);
			if (Service::hasErrors())
			{
				throw Refusal::AccountUnavailable->exception();
			}
			if ($cache->startDataCache(self::ACCOUNT_CACHE_TTL, $cacheId, self::ACCOUNT_CACHE_DIR))
			{
				$cache->endDataCache($accounts);
			}

			return $accounts;
		}
		catch (\DomainException $exception)
		{
			throw $exception;
		}
		catch (\Throwable)
		{
			throw Refusal::AccountUnavailable->exception();
		}
		finally
		{
			$service->setClientId($previousClientId);
		}
	}

	private function capabilities(string $provider): array
	{
		$supported = Service::getInstance()->getAccount($provider)->hasExpensesReport();

		return ['import' => $supported, 'tree' => $supported ? ['campaign', 'group', 'keyword'] : []];
	}

	private function accountRow(Binding $binding, string $clientId, ?string $accountId,
		string $name, array $capabilities): array
	{
		return [
			'provider' => $binding->provider,
			'clientId' => $clientId,
			'accountId' => $accountId,
			'name' => $name,
			'capabilities' => $capabilities,
			'isConnected' => $binding->clientId === $clientId && $binding->accountId === $accountId,
		];
	}
}
