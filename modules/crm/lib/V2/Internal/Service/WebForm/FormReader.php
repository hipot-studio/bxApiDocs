<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormCollection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormFilter;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormListSort;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormNotFoundException;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormRepository;
use Bitrix\Main\Provider\Params\Pager;

/**
 * @internal
 */
final class FormReader
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $formLoader;
	private readonly \Closure $formListLoader;

	/**
	 * @param null|\Closure(int): bool $permissionChecker
	 * @param null|\Closure(int, WebFormSelect): ?WebForm $formLoader
	 * @param null|\Closure(WebFormFilter, WebFormListSort, int, int, WebFormSelect): WebFormCollection $formListLoader
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $formLoader = null,
		?\Closure $formListLoader = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canRead()
		;
		$this->formLoader = $formLoader ?? static fn (int $id, WebFormSelect $select): ?WebForm =>
			(new FormRepository())->getById($id, $select)
		;
		$this->formListLoader = $formListLoader ?? static fn (
			WebFormFilter $filter,
			WebFormListSort $sort,
			int $limit,
			int $offset,
			WebFormSelect $select,
		): WebFormCollection => (new FormRepository())->findAll($filter, $sort, $limit, $offset, $select);
	}

	/**
	 * Returns a CRM form using the requested projection after checking global access.
	 */
	public function get(int $userId, int $id, WebFormSelect $select): WebForm
	{
		$this->assertCanRead($userId);

		$form = ($this->formLoader)($id, $select);
		if (!$form instanceof WebForm)
		{
			throw new FormNotFoundException();
		}

		return $form;
	}

	/**
	 * Returns a page of CRM forms using the requested projection after checking global access.
	 */
	public function list(
		int $userId,
		WebFormFilter $filter,
		WebFormListSort $sort,
		Pager $pager,
		WebFormSelect $select,
	): WebFormCollection
	{
		$this->assertCanRead($userId);

		return ($this->formListLoader)(
			$filter,
			$sort,
			$pager->getLimit(),
			$pager->getOffset(),
			$select,
		);
	}

	private function assertCanRead(int $userId): void
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}
	}
}
