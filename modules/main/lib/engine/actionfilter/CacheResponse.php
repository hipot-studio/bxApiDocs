<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionEventResult;
use Bitrix\Main\Engine\ActionFilter\CacheResponse\ContextField;
use Bitrix\Main\Engine\Response\Json;
use Bitrix\Main\Engine\Response\File;
use Bitrix\Main\Engine\Response\Zip\Archive;
use Bitrix\Main\Web;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Response;
use Bitrix\Main\Engine\Exception\ActionConfigurationException;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Closure;

/**
 * Caches action results, including null and empty values.
 * Uses the same instance in both action phases. The default TTL is one hour.
 * Request fields follow Request::get(): query and POST, with POST taking precedence.
 * JSON is separate and can be included through cacheKeyAdditionalDataCallback.
 * Cache entries are shared unless User, Ip, Site, Language or Host context fields are selected.
 * Authorization and validation must run before the action in filters, not only inside the action.
 * Responses contribute only their body and selected headers (Content-Type by default).
 * File (including BFile) and Zip Archive responses are not cached: their content is produced in send().
 * Cookies and response objects are never stored.
 * Results of rejected actions are not cached.
 * Results with Controller or Action errors are never cached.
 * Place this filter last in the post-filter list to cache the final filtered action data.
 * On a cache hit, a non-null result from a preceding post-filter replaces the cached value.
 * The cached value precedes Controller::processAfterAction() and HTTP response formatting.
 *
 * Basic attribute usage (preserves default filters):
 * ```php
 * use Bitrix\Main\Engine\ActionFilter;
 * use Bitrix\Main\Engine\ActionFilter\Attribute\Rule;
 *
 * #[Rule\EnableFilter([new ActionFilter\CacheResponse()])]
 * public function listAction(): array { return ['items' => []]; }
 * ```
 *
 * Selected request and context fields, with a callable supported in attributes:
 * ```php
 * #[Rule\EnableFilter([new ActionFilter\CacheResponse(
 *     ttl: 3600,
 *     cacheKeyRequestFields: ['code', 'test'],
 *     cachedResponseHeaders: ['Content-Type', 'Content-Language'],
 *     cacheKeyContextFields: [ActionFilter\CacheResponse\ContextField::User],
 *     cacheKeyAdditionalDataCallback: [RegionCacheKey::class, 'resolve'],
 * )])]
 * public function detailsAction(string $code, int $test): array { return []; }
 *
 * // RegionCacheKey::resolve(Action $action): array returns ['region' => 'eu'].
 * ```
 *
 * configureActions() alternative;
 * ```php
 * return ['list' => ['+filters' => [new ActionFilter\CacheResponse()]]];
 * ```
 *
 * Invalidate all entries of a controller:
 * ```php
 * CacheResponse::cleanCacheController(MyController::class)
 * ```
 */
final class CacheResponse extends Base
{
	private readonly ?Closure $cacheKeyAdditionalDataCallback;
	private ?string $cacheKey = null;
	private ?array $cachedData = null;

	/**
	 * @param string[] $cacheKeyRequestFields Selected request fields; empty by default.
	 * @param ContextField[] $cacheKeyContextFields Additional context; empty by default.
	 * @param string[] $cachedResponseHeaders Response headers to cache; [] excludes all headers.
	 * @param (callable(Action): array)|null $cacheKeyAdditionalDataCallback Additional key data, evaluated once.
	 */
	public function __construct(
		private readonly int $ttl = 3600,
		private readonly array $cacheKeyRequestFields = [],
		private readonly array $cacheKeyContextFields = [],
		?callable $cacheKeyAdditionalDataCallback = null,
		private readonly array $cachedResponseHeaders = ['Content-Type'],
	)
	{
		parent::__construct();

		if ($ttl <= 0)
		{
			throw new ActionConfigurationException('CacheResponse TTL must be positive');
		}

		$this->cacheKeyAdditionalDataCallback = $cacheKeyAdditionalDataCallback === null
			? null
			: Closure::fromCallable($cacheKeyAdditionalDataCallback)
		;
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$this->cacheKey = null;
		$this->cachedData = null;

		if ($this->hasErrors())
		{
			return null;
		}

		$this->cacheKey = $this->buildCacheKey();

		$cache = Cache::createInstance();
		$cache->noOutput();
		if (!$cache->initCache($this->ttl, $this->cacheKey, $this->getCacheFolder()))
		{
			return null;
		}

		$this->cachedData = $cache->getVars();

		return ActionEventResult::skipAction($this);
	}

	public function onAfterAction(Event $event): void
	{
		if ($this->cacheKey === null || !$event->getParameter('isActionAllowed'))
		{
			return;
		}

		if ($this->hasErrors())
		{
			return;
		}

		$result = $event->getParameter('result');
		$isCacheExists = $this->cachedData !== null;

		if ($isCacheExists && $result === null)
		{
			$result = $this->cachedData['result'];
			if ($this->cachedData['headers'] !== null)
			{
				$result = (new HttpResponse())
					->setContent($result)
					->setHeaders(new Web\HttpHeaders($this->cachedData['headers']))
					->setStatus($this->cachedData['status'])
				;
			}
			$event->setParameter('result', $result);

			return;
		}

		$status = $result instanceof HttpResponse ? $result->getStatus() : 0;
		if (
			($status >= 300 && $status < 400)
			|| $result instanceof File
			|| $result instanceof Archive
		)
		{
			return;
		}

		$headers = null;
		if ($result instanceof Response)
		{
			$headers = [];
			if ($result instanceof HttpResponse)
			{
				foreach ($this->cachedResponseHeaders as $name)
				{
					$values = $result->getHeaders()->get($name, true);
					// Json adds its Content-Type only in send(), after action filters have run.
					if ($values === null && strtolower($name) === 'content-type' && $result instanceof Json)
					{
						$values = ['application/json; charset=UTF-8'];
					}
					if ($values !== null)
					{
						$headers[$name] = $values;
					}
				}
			}

			$result = $result->getContent();
		}

		$cache = Cache::createInstance();
		$cache->noOutput();
		$cache->forceRewriting($isCacheExists);

		if ($cache->startDataCache($this->ttl, $this->cacheKey, $this->getCacheFolder()))
		{
			$cache->endDataCache([
				'result' => $result,
				'headers' => $headers,
				'status' => $status,
			]);
		}
	}

	private function hasErrors(): bool
	{
		return $this->getAction()->getErrors() !== [] || $this->getAction()->getController()->hasErrors();
	}

	private function buildCacheKey(): string
	{
		$action = $this->getAction();
		$controller = $action->getController();
		$request = $controller->getRequest();
		$requestValues = [];
		foreach ($this->cacheKeyRequestFields as $field)
		{
			$requestValues[$field] = $request->get($field);
		}

		$context = [];
		foreach ($this->cacheKeyContextFields as $field)
		{
			$context[$field->value] = match ($field)
			{
				ContextField::User => $controller->getCurrentUser()?->getId(),
				ContextField::Ip => $request->getRemoteAddress(),
				ContextField::Site => Application::getInstance()->getContext()->getSite(),
				ContextField::Language => Application::getInstance()->getContext()->getLanguage(),
				ContextField::Host => $request->getHttpHost(),
			};
		}

		$variations = $this->cacheKeyAdditionalDataCallback === null
			? []
			: $this->cacheKeyAdditionalDataCallback->__invoke($action)
		;
		if (!is_array($variations))
		{
			throw new ActionConfigurationException('CacheResponse variations callback must return an array');
		}

		$data = [
			'version' => 6,
			'controller' => $controller::class,
			'action' => strtolower($action->getName()),
			'ttl' => $this->ttl,
			'headers' => $this->cachedResponseHeaders,
			'request' => $requestValues,
			'context' => $context,
			'variations' => $variations,
		];

		return md5(serialize($data));
	}

	private function getCacheFolder(): string
	{
		return self::getControllerCacheFolder($this->getAction()->getController()::class);
	}

	private static function getControllerCacheFolder(string $controllerClassName): string
	{
		return 'main/action_response/' . md5($controllerClassName);
	}

	public static function cleanCacheController(string $controllerClassName): void
	{
		Cache::createInstance()->cleanDir(self::getControllerCacheFolder($controllerClassName));
	}
}
