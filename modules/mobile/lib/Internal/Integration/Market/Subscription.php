<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Market;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Market\Subscription\Trial;

final class Subscription
{
	/**
	 * @return bool
	 */
	public function isDemoAvailable(): bool
	{
		return
			Loader::includeModule('market')
			&& Trial::isAvailable()
			&& (
				!ModuleManager::isModuleInstalled('extranet')
				|| (Loader::includeModule('extranet') && \CExtranet::IsIntranetUser())
			)
		;
	}

	/**
	 * @return Result
	 */
	public function activateDemo(): Result
	{
		if (!$this->isDemoAvailable())
		{
			return (new Result())->addError(new Error('Subscription is not available'));
		}

		$activationResult = Trial::activate();

		if (isset($activationResult['error']))
		{
			$error = is_string($activationResult['error']) ? $activationResult['error'] : 'Unknown error';
			$errorCode =
				isset($activationResult['error_code'])
				&& (is_string($activationResult['error_code']) || is_int($activationResult['error_code']))
					? $activationResult['error_code']
					: null
			;

			return (new Result())->addError(new Error($error, $errorCode));
		}

		// Trial::activate() marks success only as ['result' => true] and returns an empty array
		// when the request was not built or the service did not respond. An error without a message
		// is intentional: the consumer shows its own localized text.
		if (($activationResult['result'] ?? null) !== true)
		{
			return (new Result())->addError(new Error('', 'ACTIVATION_FAILED'));
		}

		return (new Result())->setData($activationResult);
	}
}
