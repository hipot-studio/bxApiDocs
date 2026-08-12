<?php

namespace Bitrix\Market\Internal\Services\Mobile;

use Bitrix\Market\Loadable;
use CBitrixComponent;

final class PageResolver
{
	public const MAIN_PAGE = '/mobile/market/';

	private const PAGE_DETAIL = 'detail';
	private const PAGE_INSTALL = 'install';

	private const COMPONENTS = [
		self::PAGE_DETAIL => [
			'name' => 'bitrix:market.detail',
			'class' => \RestMarketDetail::class,
		],
	];

	private string $requestUrl;

	private array $queryParams;

	private string $pageCode;

	public function __construct(string $requestUrl, array $queryParams)
	{
		$this->requestUrl = $requestUrl;
		$this->queryParams = $queryParams;
		$this->pageCode = $this->resolvePageCode();
	}

	public static function isMobilePagePath(string $path): bool
	{
		return mb_strpos($path, self::MAIN_PAGE) === 0;
	}

	public function getComponentName(): string
	{
		$componentConfig = $this->getComponentConfig();

		return $componentConfig['name'] ?? '';
	}

	public function getComponentParams(): array
	{
		if (!$this->isSupportedPage())
		{
			return [];
		}

		$params = [
			'VARIABLES' => [],
			'VIEW_MODE' => 'mobile',
			'HIDE_TOOLBAR' => 'Y',
			'CHANGE_HISTORY' => 'N',
			'MOBILE_PAGE' => $this->pageCode,
			'REQUEST' => $this->queryParams,
		];

		$from = $this->normalizeString($this->queryParams['from'] ?? '');
		if ($from !== '')
		{
			$params['FROM'] = $from;
		}

		if (in_array($this->pageCode, [self::PAGE_DETAIL, self::PAGE_INSTALL], true))
		{
			$params['APP_CODE'] = $this->resolveAppCode();

			return $params;
		}

		return $params;
	}

	public function getComponentData(): array
	{
		if (!$this->isSupportedPage())
		{
			return [
				'params' => [],
				'result' => [],
			];
		}

		$componentConfig = $this->getComponentConfig();

		CBitrixComponent::includeComponentClass($componentConfig['name']);

		$class = $componentConfig['class'];
		$component = new $class();

		if (!$component instanceof Loadable)
		{
			return [];
		}

		return $component->getAjaxData($this->getComponentParams());
	}

	private function resolvePageCode(): string
	{
		$queryPage = mb_strtolower($this->normalizeString($this->queryParams['page'] ?? ''));
		if (
			in_array(
				$queryPage,
				[self::PAGE_DETAIL, self::PAGE_INSTALL],
				true,
			)
		)
		{
			return $queryPage;
		}

		$normalizedPath = $this->normalizePath($this->requestUrl);

		if (
			$normalizedPath === '/mobile/market/detail'
			|| mb_strpos($normalizedPath, '/mobile/market/detail/') === 0
		)
		{
			return self::PAGE_DETAIL;
		}

		if (
			$normalizedPath === '/mobile/market/install'
			|| mb_strpos($normalizedPath, '/mobile/market/install/') === 0
		)
		{
			return self::PAGE_INSTALL;
		}

		return '';
	}

	private function getComponentConfig(): array
	{
		if (
			$this->pageCode === self::PAGE_DETAIL
			|| $this->pageCode === self::PAGE_INSTALL
		)
		{
			return self::COMPONENTS[self::PAGE_DETAIL];
		}

		return [];
	}

	private function isSupportedPage(): bool
	{
		return $this->pageCode !== '';
	}

	private function resolveAppCode(): string
	{
		$appCode = $this->normalizeString($this->queryParams['appCode'] ?? '');
		if ($appCode !== '')
		{
			return $appCode;
		}

		if (preg_match('#^/mobile/market/(?:detail|install)/([^/]+)/?#', $this->normalizePath($this->requestUrl), $matches) === 1)
		{
			return $this->normalizeString(urldecode($matches[1]));
		}

		return '';
	}

	private function normalizePath(string $requestUrl): string
	{
		$path = trim($requestUrl);
		if ($path === '')
		{
			return '';
		}

		$questionMarkPosition = mb_strpos($path, '?');
		if ($questionMarkPosition !== false)
		{
			$path = mb_substr($path, 0, $questionMarkPosition);
		}

		$path = '/' . trim($path, '/');

		return rtrim($path, '/');
	}

	private function normalizeString($value, string $fallback = ''): string
	{
		$preparedValue = is_string($value)
			? trim($value)
			: '';

		return $preparedValue !== '' ? $preparedValue : $fallback;
	}
}
