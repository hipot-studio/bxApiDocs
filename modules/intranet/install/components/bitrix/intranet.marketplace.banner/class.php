<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Intranet\Internal\Marketplace\Banner\Managed\Campaign;
use Bitrix\Intranet\Internal\Marketplace\Banner\Managed\RemoteConfiguration;
use Bitrix\Intranet\Internal\Marketplace\Banner\Managed\RemoteStatus;
use Bitrix\Intranet\Internal\Marketplace\Banner\ProjectionCache;
use Bitrix\Intranet\Internal\Marketplace\Banner\ProjectionResolver;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;

class IntranetMarketplaceBannerComponent extends CBitrixComponent implements Controllerable
{
	public function configureActions(): array
	{
		return [
			'refresh' => [
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			],
		];
	}

	public function executeComponent(): void
	{
		try
		{
			$resolution = $this->resolveInitial($this->getLanguage());
		}
		catch (\Throwable)
		{
			$resolution = ['projection' => null, 'refreshRequired' => false];
		}
		$this->arResult = [
			'projection' => $resolution['projection'],
			'refreshRequired' => $resolution['refreshRequired'],
		];
		$this->includeTemplate();
	}

	public function refreshAction(): array
	{
		$updateResult = $this->updateRemoteConfiguration();
		if ($updateResult === null)
		{
			return ['projection' => null];
		}

		if ($updateResult['updated'])
		{
			$this->cleanProjectionCache();
		}

		return [
			'projection' => $this->resolveManagedProjection($this->getLanguage()),
		];
	}

	/**
	 * @return array{projection: array|null, refreshRequired: bool}
	 */
	protected function resolveInitial(string $language): array
	{
		$resolution = ProjectionResolver::createDefault()?->resolveInitial($language);
		if ($resolution === null)
		{
			return ['projection' => null, 'refreshRequired' => false];
		}

		return [
			'projection' => $resolution['projection']?->toArray(),
			'refreshRequired' => $resolution['refreshRequired'],
		];
	}

	protected function resolveManagedProjection(string $language): ?array
	{
		return ProjectionResolver::createDefault()?->resolveManaged($language)?->toArray();
	}

	/**
	 * @return array{status: RemoteStatus, checkedAt: int, campaign: Campaign|null, updated: bool}|null
	 */
	protected function updateRemoteConfiguration(): ?array
	{
		if (!ProjectionResolver::isAvailable())
		{
			return null;
		}

		return $this->createRemoteConfiguration()->update();
	}

	protected function cleanProjectionCache(): void
	{
		$this->createProjectionCache()->clean();
	}

	protected function createRemoteConfiguration(): RemoteConfiguration
	{
		return new RemoteConfiguration();
	}

	protected function createProjectionCache(): ProjectionCache
	{
		return new ProjectionCache();
	}

	protected function getLanguage(): string
	{
		return (string)LANGUAGE_ID;
	}

	protected function includeTemplate(): void
	{
		$this->includeComponentTemplate();
	}
}
