<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\DelayedTask\Processor;

use Bitrix\Booking\Internals\Service\DelayedTask\Data\DataInterface;

interface ProcessorInterface
{
	public function __invoke(): void;

	public static function checkData(DataInterface $data): bool;
}
