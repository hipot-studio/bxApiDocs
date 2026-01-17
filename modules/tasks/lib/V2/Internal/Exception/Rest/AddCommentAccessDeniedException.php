<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Exception\Rest;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

class AddCommentAccessDeniedException extends SystemException
{
	/**
	 * @inheritDoc
	 */
	public function __construct($message = '', $code = 0, $file = '', $line = 0, \Throwable $previous = null)
	{
		if (!$message)
		{
			$message = Loc::getMessage('TASK_REST_ADD_COMMENT_ACCESS_DENIED');
		}

		parent::__construct($message, $code, $file, $line, $previous);
	}
}
