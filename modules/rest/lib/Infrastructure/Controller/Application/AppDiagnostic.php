<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Controller\Application;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Rest\Infrastructure\Security\HandlerUrlSignature;
use Bitrix\Rest\Internal\Integration\Main\Web\HandlerOriginResolver;
use Bitrix\Rest\Internal\Integration\Main\Web\HostDiagnosis;
use Bitrix\Rest\Internal\Integration\Main\Web\HostInspector;

class AppDiagnostic extends Controller
{
	private const CACHE_TTL = 600;
	private const CACHE_DIR = '/rest/app_handler_certificate';

	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[Rule\Scope(ActionFilter\Scope::NOT_REST)]
	public function diagnoseAction(string $signedHandlerUrl): ?array
	{
		$url = $this->getUrlSignature()->extract($signedHandlerUrl);
		if ($url === null)
		{
			$this->addError(new Error('Handler url signature is not valid.', 'ERROR_INVALID_HANDLER_SIGNATURE'));

			return null;
		}

		$origin = $this->getOriginResolver()->resolve($url);
		if ($origin === null)
		{
			$this->addError(new Error('Handler is not inspectable.', 'ERROR_HANDLER_NOT_INSPECTABLE'));

			return null;
		}

		return [
			'reason' => $this->getHostDiagnosis($origin)->value,
		];
	}

	protected function getUrlSignature(): HandlerUrlSignature
	{
		return new HandlerUrlSignature();
	}

	protected function getOriginResolver(): HandlerOriginResolver
	{
		return new HandlerOriginResolver();
	}

	protected function getHostInspector(): HostInspector
	{
		return new HostInspector();
	}

	private function getHostDiagnosis(string $origin): HostDiagnosis
	{
		$cache = Cache::createInstance();
		$cacheId = 'rest.app_handler_certificate.v3.' . $origin;

		if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$cached = $cache->getVars();
			$cachedDiagnosis = is_array($cached) && is_string($cached['reason'] ?? null) ? $cached['reason'] : '';

			return HostDiagnosis::tryFrom($cachedDiagnosis) ?? HostDiagnosis::Unknown;
		}

		$diagnosis = $this->getHostInspector()->inspect($origin);

		if ($cache->startDataCache())
		{
			$cache->endDataCache(['reason' => $diagnosis->value]);
		}

		return $diagnosis;
	}
}
