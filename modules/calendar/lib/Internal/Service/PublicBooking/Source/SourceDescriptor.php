<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking\Source;

/**
 * Value object describing the booking source from an incoming request.
 *
 * For a 'crm-webform' source, $params contains:
 *   - formId: int
 *   - sec: string
 *   - fieldCode: string
 */
final class SourceDescriptor
{
	public function __construct(
		private readonly string $type,
		private readonly array $params,
	)
	{
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getParams(): array
	{
		return $this->params;
	}

	public function getParam(string $key): mixed
	{
		return $this->params[$key] ?? null;
	}
}
