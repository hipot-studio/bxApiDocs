<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\AiAgent;

use Bitrix\Main\Error;

interface NodeAvailabilityServiceInterface
{
	/**
	 * Returns true if AI agent nodes may be registered and used in the portal.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Returns the error to show when an AI agent node is used while unavailable.
	 * Centralizes the error text and code on the bizproc side so consumers
	 * (activities from any module) report a consistent message.
	 *
	 * @return Error
	 */
	public function getUnavailableError(): Error;
}
