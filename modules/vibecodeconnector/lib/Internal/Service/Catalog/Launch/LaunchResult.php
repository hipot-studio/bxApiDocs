<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch;

final class LaunchResult
{
	public const KIND_APP = 'app';

	public function __construct(
		public readonly string $kind,
		public readonly ?LaunchAppTarget $app = null,
	) {}
}
