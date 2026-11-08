<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Admission\Strategy;

use Bitrix\Rest\Internal\Service\Admission\DenialStrategyInterface;
use Bitrix\Rest\Internal\Service\Admission\Verdict;

final class AlwaysAvailableMethodStrategy implements DenialStrategyInterface
{
	private const ALWAYS_AVAILABLE_METHODS = ['rest.portal.license.get'];

	public function __construct(private readonly ?string $method)
	{
	}

	public function resolve(): ?Verdict
	{
		return in_array($this->method, self::ALWAYS_AVAILABLE_METHODS, true)
			? Verdict::grant()
			: null;
	}
}
