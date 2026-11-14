<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter;

class LimiterChain
{
	private array $limiters;

	public function __construct(LimiterInterface ...$limiters)
	{
		$this->limiters = $limiters;
	}

	public function run(callable $call): mixed
	{
		foreach ($this->limiters as $limiter)
		{
			$limiter->check();
		}

		foreach ($this->limiters as $limiter)
		{
			$limiter->start();
		}

		try
		{
			return $call();
		}
		catch (\Throwable $e)
		{
			foreach ($this->limiters as $limiter)
			{
				$e = $limiter->catchException($e);
			}

			throw $e;
		}
		finally
		{
			foreach ($this->limiters as $limiter)
			{
				$limiter->finish();
			}
		}
	}

	public function getDebugInfo(): array
	{
		return array_merge(...array_map(
			static fn(LimiterInterface $limiter): array => $limiter->getDebugInfo(),
			$this->limiters,
		));
	}
}
