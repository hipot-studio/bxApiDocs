<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Dto;

/**
 * Whether the chart analysis through BitrixGPT is offered on a report to a user.
 */
final class ChartAnalysisAvailability
{
	public const HIDDEN = 'hidden';
	public const SUBSCRIPTION_REQUIRED = 'subscription_required';
	public const READY = 'ready';

	/**
	 * @param string $state One of the state constants.
	 * @param string|null $promoterCode Promoter slider code, set for SUBSCRIPTION_REQUIRED only.
	 */
	public function __construct(
		public readonly string $state,
		public readonly ?string $promoterCode = null,
	)
	{
	}

	/**
	 * @return array{state: string, promoterCode: ?string}
	 */
	public function toArray(): array
	{
		return [
			'state' => $this->state,
			'promoterCode' => $this->promoterCode,
		];
	}
}
