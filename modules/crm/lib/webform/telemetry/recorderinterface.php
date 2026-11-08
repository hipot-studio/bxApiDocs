<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Telemetry;

interface RecorderInterface
{
	/**
	 * Registers a new attempt unless the form exhausted its daily quota of public attempts.
	 */
	public function start(string $attemptId, int $formId, string $sourceHint, ?string $userAgent): void;

	public function markError(string $attemptId, string $errorType): void;

	public function complete(string $attemptId, int $formId, array $fields, ?string $templateId): void;
}
