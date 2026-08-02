<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

final readonly class RoundTripReport
{
	/**
	 * @param list<string> $lost canonical "from:port -> to:port" between known activities
	 * @param list<string> $extra
	 */
	public function __construct(
		public array $lost,
		public array $extra,
	) {}

	public function isClean(): bool
	{
		return $this->lost === [] && $this->extra === [];
	}
}
