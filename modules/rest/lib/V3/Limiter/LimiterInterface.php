<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter;

interface LimiterInterface
{
	public function check(): void;

	public function start(): void;

	public function catchException(\Throwable $e): \Throwable;

	public function finish(): void;

	public function getDebugInfo(): array;
}
