<?php

declare(strict_types=1);

namespace Bitrix\Intranet\License\Widget\Content;

use Bitrix\Intranet\User;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

final class VibeBalance extends BaseContent
{
	private readonly \Closure $provider;

	public function __construct(?\Closure $provider = null)
	{
		$this->provider = $provider ?? static function (): array {
			$userId = (int)CurrentUser::get()->getId();
			if (
				($userId > 0 && (new User($userId))->isIntegrator())
				|| !Loader::includeModule('vibecodeconnector')
				|| !(new \Bitrix\Vibecodeconnector\Public\Service\AvailabilityService())->isVibeBalanceEnabled()
			)
			{
				return [
					'isAvailable' => false,
					'balanceState' => 'unavailable',
					'balance' => null,
					'action' => 'none',
				];
			}

			return (new \Bitrix\Vibecodeconnector\Public\Provider\VibeBalanceProvider())
				->getForCurrentUser()
				->toArray();
		};
	}

	public function getName(): string
	{
		return 'vibeBalance';
	}

	public function getConfiguration(): array
	{
		return ($this->provider)();
	}
}
