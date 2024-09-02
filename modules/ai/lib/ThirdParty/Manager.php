<?php

namespace Bitrix\AI\ThirdParty;

use Bitrix\AI\Cache;
use Bitrix\AI\Facade\Rest;
use Bitrix\AI\Engine;
use Bitrix\AI\Model\EngineTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Rest\RestException;

Loc::loadMessages(__FILE__);

class Manager
{
	private const CACHE_DIR = 'ai.thirdparty01';

	/**
	 * Registers new custom Engine.
	 *
	 * @param array $data Array contains fields: ['name', 'code', 'category', 'completions_url', 'settings'].
	 * @param mixed $service During REST executes.
	 * @param mixed $server During REST executes.
	 * @return int
	 */
	public static function register(array $data, mixed $service = null, mixed $server = null): int
	{
		$data = array_change_key_case($data);

		// check common errors
		foreach (['name', 'code', 'category', 'completions_url'] as $code)
		{
			$data[$code] = $data[$code] ?? '';
			if (!is_string($data[$code]) || !mb_strlen(trim($data[$code])))
			{
				$codeUp = mb_strtoupper($code);
				throw new RestException(
					Loc::getMessage("AI_REST_ENGINE_REGISTER_ERROR_$codeUp"),
					"ENGINE_REGISTER_ERROR_$codeUp"
				);
			}
		}

		$data['code'] = trim($data['code']);

		// code format validation
		if (!preg_match('/^[A-Za-z0-9-_]+$/', $data['code']))
		{
			throw new RestException(
				Loc::getMessage('AI_REST_ENGINE_REGISTER_ERROR_CODE_FORMAT'),
				'ENGINE_REGISTER_ERROR_CODE_FORMAT'
			);
		}

		// category validation
		$categories = Engine::getCategories();
		if (!in_array($data['category'], $categories))
		{
			throw new RestException(
				Loc::getMessage('AI_REST_ENGINE_REGISTER_ERROR_CATEGORY_FORMAT', ['{categories}' => implode(', ', $categories)]),
				'ENGINE_REGISTER_ERROR_CATEGORY_FORMAT'
			);
		}

		// code unique validation
		if (Engine::isExistByCode($data['category'], $data['code']))
		{
			throw new RestException(
				Loc::getMessage('AI_REST_ENGINE_REGISTER_ERROR_CODE_UNIQUE'),
				'ENGINE_REGISTER_ERROR_CODE_UNIQUE'
			);
		}

		// ping url
		$http = new HttpClient();
		$http->get($data['completions_url']);
		if ($http->getStatus() !== 200)
		{
			throw new RestException(
				Loc::getMessage('AI_REST_ENGINE_REGISTER_ERROR_COMPLETIONS_URL_FAIL'),
				'ENGINE_REGISTER_ERROR_COMPLETIONS_URL_FAIL'
			);
		}

		// Application code (for REST executions)
		$data['app_code'] = Rest::getApplicationCode($server?->getClientId());

		// check Engine is exists
		$existing = EngineTable::query()
			->setSelect(['ID'])
			->where('code', $data['code'])
			->where('app_code', $data['app_code'])
			->setLimit(1)
			->fetch()
		;
		// update existing or add new
		if ($existing)
		{
			$res = EngineTable::update($existing['ID'], $data);
		}
		else
		{
			$data['date_create'] = new DateTime;
			$res = EngineTable::add($data);
		}

		// return result
		if ($res->isSuccess())
		{
			Cache::remove(self::CACHE_DIR);
			return $res->getId();
		}
		else
		{
			$error = $res->getErrors()[0];
			throw new RestException($error->getMessage(), $error->getCode());
		}
	}

	/**
	 * Remove existing custom Engine.
	 *
	 * @param array $data Array contains fields: ['code'].
	 * @param mixed $service During REST executes.
	 * @param mixed $server During REST executes.
	 * @return bool
	 */
	public static function unRegister(array $data, mixed $service = null, mixed $server = null): bool
	{
		$data = array_change_key_case($data);

		$code = $data['code'] ?? null;
		$appCode = Rest::getApplicationCode($server?->getClientId());

		$existing = EngineTable::query()
			->setSelect(['ID'])
			->where('code', $code)
			->where('app_code', $appCode)
			->setLimit(1)
			->fetch()
		;
		if ($existing)
		{
			if (EngineTable::delete($existing['ID'])->isSuccess())
			{
				Cache::remove(self::CACHE_DIR);
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes all Engines by Application code.
	 *
	 * @param string $appCode Application code.
	 * @return void
	 */
	public static function deleteByAppCode(string $appCode): void
	{
		$engines = EngineTable::query()
			->setSelect(['ID'])
			->where('app_code', $appCode)
		;
		foreach ($engines->fetchAll() as $engine)
		{
			EngineTable::delete($engine['ID'])->isSuccess();
		}
	}

	/**
	 * Returns collection of Engines.
	 *
	 * @param array|null $data Maybe an array with `filter` and `limit` key.
	 * @param mixed $service During REST executes.
	 * @param mixed $server During REST executes.
	 * @return array
	 */
	public static function getList(?array $data = null, mixed $service = null, mixed $server = null): array
	{
		if ($data)
		{
			$data = array_change_key_case($data);
		}

		$list = [];

		$filter = $data['filter'] ?? [];
		if ($server?->getClientId())
		{
			$filter['app_code'] = Rest::getApplicationCode($server->getClientId());
		}

		$engines = EngineTable::query()
			->setSelect(['*'])
			->setFilter($filter)
			->setOrder(['ID' => 'asc'])
			->setLimit($data['limit'] ?? null)
		;
		foreach ($engines->fetchAll() as $engine)
		{
			$engine = array_change_key_case($engine);

			$dateCreate = time();
			if (!empty($engine['date_create']) && ($engine['date_create'] instanceof DateTime))
			{
				$dateCreate = $engine['date_create']->getTimestamp();
			}
			$engine['date_create'] = $dateCreate;

			$list[] = $engine;
		}

		return $list;
	}

	/**
	 * Returns collection of Engines.
	 *
	 * @param array|null $data Maybe an array with `filter` and `limit` key.
	 * @return Collection
	 */
	public static function getCollection(?array $data = null): Collection
	{
		if ($data)
		{
			$data = array_change_key_case($data);
		}

		$collection = [];
		$engines = empty($data)
			? Cache::get(self::CACHE_DIR, fn() => self::getList($data))
			: self::getList($data)
		;

		foreach ($engines as $engine)
		{
			$collection[] = new Item(
				$engine['id'],
				$engine['name'],
				$engine['code'],
				$engine['app_code'],
				$engine['category'],
				$engine['completions_url'],
				$engine['settings'],
				DateTime::createFromTimestamp($engine['date_create']),
			);
		}

		return new Collection($collection);
	}

	/**
	 * Returns Engine by code.
	 *
	 * @param string $code Engine's code.
	 * @return Item|null
	 */
	public static function getByCode(string $code): ?Item
	{
		$collection = Manager::getCollection([
			'filter' => ['=CODE' => $code],
			'limit' => 1,
		]);

		return !$collection->isEmpty() ? $collection->current() : null;
	}

	/**
	 * Checks Engine exists by code.
	 * @param string $code Engine's code.
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function hasEngine(string $code): bool
	{
		return (bool) EngineTable::query()
			->setSelect(['ID'])
			->where('code', $code)
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * Checks ThirdParty Engines exists.
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function hasEngines(): bool
	{
		return (bool) EngineTable::query()
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;
	}
}
