<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\WebForm;

use Bitrix\Main\Type\DateTime;
use RuntimeException;

/**
 * @internal
 */
final class FormVersionMismatchException extends RuntimeException
{
	public function __construct(private readonly DateTime $currentModifiedTime)
	{
		parent::__construct('CRM form was changed after it was read.');
	}

	public function getCurrentModifiedTime(): DateTime
	{
		return $this->currentModifiedTime;
	}
}
