<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Web\Uri;

class UnifiedLinkUrl
{
	public const PARAMETER_NAME = '_uls';

	/**
	 * @param string $url
	 * @param string $uls
	 * @return string
	 */
	public function addUlsToUrl(string $url, string $uls): string
	{
		return (string)$this->addUlsToUri(new Uri($url), $uls);
	}

	/**
	 * @param Uri $uri
	 * @param string $uls
	 * @return Uri
	 */
	public function addUlsToUri(Uri $uri, string $uls): Uri
	{
		$uri->addParams($this->addUlsToParams([], $uls));

		return $uri;
	}

	/**
	 * @param array $params
	 * @param string $uls
	 * @return array
	 */
	public function addUlsToParams(array $params, string $uls): array
	{
		return $this->addUlsToParamsInternal($params, static::PARAMETER_NAME, $uls);
	}

	/**
	 * @param HttpRequest $request
	 * @return string|null
	 */
	public function getUlsFromRequest(HttpRequest $request): ?string
	{
		return $this->getParameterFromRequest($request, static::PARAMETER_NAME);
	}

	/**
	 * @param array $params
	 * @param string $parameterName
	 * @param string $uls
	 * @return array
	 */
	protected function addUlsToParamsInternal(array $params, string $parameterName, string $uls): array
	{
		$params[$parameterName] = $uls;

		return $params;
	}

	/**
	 * @param HttpRequest $request
	 * @param string $parameterName
	 * @return string|null
	 */
	protected function getParameterFromRequest(HttpRequest $request, string $parameterName): ?string
	{
		$value = $request->get($parameterName);

		if (is_string($value) && $value !== '')
		{
			return $value;
		}

		return null;
	}
}
