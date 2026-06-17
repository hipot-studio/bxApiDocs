<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row;

use Bitrix\Main\Grid\Row\Rows;

class MailboxRows extends Rows
{
	/**
	 * @param array $rawValue Raw row data (mailbox or connection request).
	 * @return array{
	 *     id: string|int,
	 *     data: array,
	 *     actions: array,
	 *     attrs?: array{data-connection-request: string},
	 *     editable?: bool,
	 * }
	 */
	protected function prepareRow(array $rawValue): array
	{
		$result = parent::prepareRow($rawValue);

		if (!empty($rawValue['IS_CONNECTION_REQUEST']))
		{
			$result['attrs'] = ['data-mailbox-connection-request' => 'true'];
			$result['editable'] = false;
		}

		return $result;
	}
}
