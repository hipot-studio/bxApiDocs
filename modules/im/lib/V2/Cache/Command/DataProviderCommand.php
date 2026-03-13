<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache\Command;

class DataProviderCommand
{
	/** @var callable(): mixed */
	private $provider;

	/**
	 * @param callable(): mixed $provider
	 */
	public function __construct(callable $provider)
	{
		$this->provider = $provider;
	}

	public function __invoke(): mixed
	{
		return ($this->provider)();
	}
}
