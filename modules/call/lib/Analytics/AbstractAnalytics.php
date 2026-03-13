<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Call\Call;
use Bitrix\Main\Application;

/**
 * @internal
 */
abstract class AbstractAnalytics
{
	protected Call $call;

	public function __construct(Call $call)
	{
		$this->call = $call;
	}

	protected function async(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}
}
