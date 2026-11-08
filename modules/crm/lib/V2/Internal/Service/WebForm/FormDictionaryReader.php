<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\EntityField;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\Scenario;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\ScenarioCategory;
use Bitrix\Crm\V2\Internal\Exception\WebForm\FormAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\UnknownFormEntityException;
use Bitrix\Crm\V2\Internal\Repository\WebForm\FormDictionaryRepository;

/**
 * Reads the three dictionaries an editor of CRM forms is built on: the scenarios a form may be
 * created from, the groups those scenarios are offered in and the CRM fields a form may be built of.
 *
 * A dictionary names no form, so nothing that stands in front of a particular form reaches it: the
 * right to read CRM forms is the whole of the check, and it is asked before the data.
 *
 * The read changes nothing on the portal.
 *
 * @internal
 */
final class FormDictionaryReader
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $scenariosLoader;
	private readonly \Closure $scenarioCategoriesLoader;
	private readonly \Closure $entityFieldsLoader;

	/**
	 * @param null|\Closure(int): bool $permissionChecker
	 * @param null|\Closure(): list<Scenario> $scenariosLoader
	 * @param null|\Closure(): list<ScenarioCategory> $scenarioCategoriesLoader
	 * @param null|\Closure(?array): list<EntityField> $entityFieldsLoader
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $scenariosLoader = null,
		?\Closure $scenarioCategoriesLoader = null,
		?\Closure $entityFieldsLoader = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId): bool =>
			Container::getInstance()
				->getUserPermissions($userId)
				->webForm()
				->canRead()
		;
		$this->scenariosLoader = $scenariosLoader ?? static fn (): array =>
			(new FormDictionaryRepository())->getScenarios()
		;
		$this->scenarioCategoriesLoader = $scenarioCategoriesLoader ?? static fn (): array =>
			(new FormDictionaryRepository())->getScenarioCategories()
		;
		$this->entityFieldsLoader = $entityFieldsLoader ?? static fn (?array $entityNames): array =>
			(new FormDictionaryRepository())->getEntityFields($entityNames)
		;
	}

	/**
	 * Returns the scenarios a form may be created from.
	 *
	 * @return list<Scenario>
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 */
	public function listScenarios(int $userId): array
	{
		$this->assertCanReadForms($userId);

		return ($this->scenariosLoader)();
	}

	/**
	 * Returns the groups the scenarios of a form are offered in.
	 *
	 * @return list<ScenarioCategory>
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 */
	public function listScenarioCategories(int $userId): array
	{
		$this->assertCanReadForms($userId);

		return ($this->scenarioCategoriesLoader)();
	}

	/**
	 * Returns the CRM fields a form may be built of, all of them or those of the named entities.
	 *
	 * The names are a narrowing of the read and not a projection over its result: what they leave
	 * out is never built. Naming no entity at all is an answer of no field, and naming none of them
	 * - passing null - is the whole dictionary.
	 *
	 * @param null|list<string> $entityNames
	 * @return list<EntityField>
	 *
	 * @throws FormAccessDeniedException the user may not read CRM forms
	 * @throws UnknownFormEntityException one of the names belongs to no entity of the dictionary
	 */
	public function listEntityFields(int $userId, ?array $entityNames = null): array
	{
		$this->assertCanReadForms($userId);

		return ($this->entityFieldsLoader)($entityNames);
	}

	private function assertCanReadForms(int $userId): void
	{
		if (!(bool)($this->permissionChecker)($userId))
		{
			throw new FormAccessDeniedException();
		}
	}
}
