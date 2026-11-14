<?php

namespace Bitrix\Crm\Component\InvoiceEdit;

use Bitrix\Main\Context;

/**
 * Deleting a legacy invoice from its card (GET .../edit/<ID>/?delete=&sessid=...) is decided by
 * the delete permission only, as it is in the list. For such a request crm.invoice.edit skips
 * its update/add entry checks and reads the invoice with the DELETE permission; the delete
 * handler of the component checks canDelete itself.
 */
final class DeleteRequest
{
	private const PERMISSION_DELETE = 'DELETE';
	private const PERMISSION_WRITE = 'WRITE';

	public function __construct(
		private readonly int $invoiceId,
		private readonly bool $isGet,
		private readonly array $query,
		private readonly bool $isSessionValid,
	)
	{
	}

	public static function createFromCurrentRequest(int $invoiceId): self
	{
		$request = Context::getCurrent()->getRequest();

		return new self(
			$invoiceId,
			$request->getRequestMethod() === 'GET',
			$request->getQueryList()->toArray(),
			check_bitrix_sessid(),
		);
	}

	/**
	 * POST is left out because the component saves the form on a POST with a valid sessid,
	 * and "expose" because it issues a new invoice from a recurring template: neither may
	 * be reached with the delete permission alone.
	 */
	public function isDelete(): bool
	{
		return
			$this->invoiceId > 0
			&& $this->isGet
			&& array_key_exists('delete', $this->query)
			&& ($this->query['expose'] ?? null) !== 'Y'
			&& $this->isSessionValid
		;
	}

	public function getLoadPermission(): string
	{
		return $this->isDelete() ? self::PERMISSION_DELETE : self::PERMISSION_WRITE;
	}
}
