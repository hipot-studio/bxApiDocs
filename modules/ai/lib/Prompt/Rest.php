<?php

namespace Bitrix\AI\Prompt;

use Bitrix\AI\Facade;
use Bitrix\AI\Synchronization\PromptSync;
use Bitrix\Rest\RestException;

/**
 * Proxy class for REST purpose.
 */
class Rest
{
	/**
	 * Adds or updates Prompt.
	 *
	 * @param array $data Array contains fields: ['category', 'section', 'sort', 'code', 'parent_code', 'icon', 'prompt', 'translate', 'work_with_result'].
	 * @param mixed $service During REST executes.
	 * @param mixed $server During REST executes.
	 * @return int
	 */
	public static function register(array $data, mixed $service = null, mixed $server = null): int
	{
		$data = array_change_key_case($data);

		$data['hash'] = md5(json_encode($data));
		$data['app_code'] = Facade\Rest::getApplicationCode($server?->getClientId());

		// prevent write system fields
		foreach (['settings', 'is_system'] as $code)
		{
			if (array_key_exists($code, $data))
			{
				unset($data[$code]);
			}
		}

		if (array_key_exists('code', $data))
		{
			// special naming for REST's prompts
			if (!str_starts_with($data['code'], 'rest_'))
			{
				throw new RestException(
					'Prompt code must starts with \'rest_\'.',
					'PROMPT_CODE_MUST_START_WITH_REST'
				);
			}
			// code format validation
			if (!preg_match('/^[A-Za-z0-9-_]+$/', $data['code']))
			{
				throw new RestException(
					'Prompt\'s code must contains only next symbols: a-z 0-9 _.',
					'PROMPT_CODE_VALIDATION'
				);
			}
		}

		// prevent overwrite exists Prompt
		if (array_key_exists('code', $data))
		{
			if (Manager::getByCode($data['code']))
			{
				throw new RestException(
					'Prompt code exists',
					'PROMPT_CODE_EXISTS'
				);
			}
		}

		$res = (new PromptSync())->syncPrompt($data);

		if (!$res->isSuccess())
		{
			foreach ($res->getErrors() as $error)
			{
				throw new RestException(
					$error->getMessage(),
					$error->getCode()
				);
			}
		}

		return $res->getId();
	}

	/**
	 * Removes existing Prompt.
	 *
	 * @param array $data Array contains fields: ['code'].
	 * @param mixed $service During REST executes.
	 * @param mixed $server During REST executes.
	 * @return bool
	 */
	public static function unRegister(array $data, mixed $service = null, mixed $server = null): bool
	{
		$data = array_change_key_case($data);

		return Manager::deleteByFilter([
			'code' => $data['code'] ?? null,
			'app_code' => Facade\Rest::getApplicationCode($server?->getClientId()),
		]);
	}
}