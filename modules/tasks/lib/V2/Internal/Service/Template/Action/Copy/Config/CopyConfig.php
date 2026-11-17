<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Copy\Config;

use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;

class CopyConfig
{
	public function __construct(
		public readonly int $userId,
		public readonly ?AuthorAssignmentSubject $authorSubject = null,
	)
	{

	}
}
