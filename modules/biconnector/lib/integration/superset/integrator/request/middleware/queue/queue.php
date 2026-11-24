<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware\Queue;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

abstract class Queue
{
	/** @var Middleware\Base[] */
	private array $queue = [];

	final public function add(Middleware\Base $middleware): void
	{
		foreach ($this->queue as $i => $queueMiddleware)
		{
			if ($queueMiddleware->getMiddlewareId() === $middleware->getMiddlewareId())
			{
				$this->queue[$i] = $middleware;

				return;
			}
		}

		$this->queue[] = $middleware;
	}

	/**
	 * Puts a middleware on the place of another one, keeping its position in the queue.
	 *
	 * An action that swaps a gate for a stricter or a looser one has to keep the point of the queue
	 * the gate was placed at: remove() plus add() would move it behind every middleware that follows,
	 * and the work of those middlewares would then be done before the gate decides.
	 */
	final public function replace(string $middlewareId, Middleware\Base $middleware): void
	{
		if ($middleware->getMiddlewareId() !== $middlewareId)
		{
			$this->remove($middleware->getMiddlewareId());
		}

		foreach ($this->queue as $i => $queueMiddleware)
		{
			if ($queueMiddleware->getMiddlewareId() === $middlewareId)
			{
				$this->queue[$i] = $middleware;

				return;
			}
		}

		$this->queue[] = $middleware;
	}

	final public function remove(string $middlewareId): void
	{
		for ($queueSize = count($this->queue), $i = 0; $i < $queueSize; $i++)
		{
			if ($this->queue[$i]->getMiddlewareId() === $middlewareId)
			{
				array_splice($this->queue, $i, 1);

				return;
			}
		}
	}

	protected function getQueue(): array
	{
		return $this->queue;
	}
}
