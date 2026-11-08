<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\LegalRegistry;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\SubjectLinkTable;
use Bitrix\Crm\V2\Internal\Repository\LegalRegistry\SubjectRepository;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\SubjectReference;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Query\Query;

final class RegistryAccess
{
	public function __construct(private readonly UserPermissions $permissions)
	{
	}

	public static function forUser(int $userId): self
	{
		return new self(Container::getInstance()->getUserPermissions($userId));
	}

	public function canReadForm(): bool
	{
		return $this->permissions->getUserId() > 0 && $this->permissions->webForm()->canRead();
	}

	public function canEditForm(): bool
	{
		return $this->permissions->getUserId() > 0 && $this->permissions->webForm()->canEdit();
	}

	/** @param list<SubjectReference> $bindings */
	public function canReadBindings(array $bindings): bool
	{
		if (!$this->canReadForm())
		{
			return false;
		}
		if ($bindings === [])
		{
			return $this->permissions->isAdmin();
		}
		foreach ($bindings as $binding)
		{
			if (!$binding instanceof SubjectReference || !$this->permissions->item()->canRead($binding->entityTypeId,
				$binding->entityId)) { return false; }
		}

		return true;
	}

	/** @param list<SubjectReference> $bindings */
	public function canUpdateBindings(array $bindings): bool
	{
		if (!$this->canEditForm() || $bindings === [])
		{
			return false;
		}
		foreach ($bindings as $binding)
		{
			if (!$binding instanceof SubjectReference || !$this->permissions->item()->canUpdate($binding->entityTypeId,
				$binding->entityId)) { return false; }
		}

		return true;
	}

	/** @return list<SubjectReference> */
	public function currentBindings(int $subjectId): array
	{
		$canonicalId = (new SubjectRepository())->canonicalId($subjectId);
		$bindings = [];
		foreach (SubjectLinkTable::getList(['filter' => ['=SUBJECT_ID' => $canonicalId, '=IS_CURRENT' => true]])->fetchAll() as $row)
		{
			$bindings[] = new SubjectReference((int)$row['ENTITY_TYPE_ID'], (int)$row['ENTITY_ID']);
		}

		return $bindings;
	}

	public function canReadSubject(int $subjectId): bool
	{
		try { return $this->canReadBindings($this->currentBindings($subjectId)); }
		catch (\Throwable) { return false; }
	}

	public function canUpdateSubject(int $subjectId): bool
	{
		try { return $this->canUpdateBindings($this->currentBindings($subjectId)); }
		catch (\Throwable) { return false; }
	}

	public function applySubjectReadScope(Query $query, string $subjectField = 'SUBJECT_ID'): Query
	{
		$expression = $this->subjectReadExpression();
		$query->registerRuntimeField((new ExpressionField('LEGAL_REGISTRY_ALLOWED', $expression,
			array_fill(0, substr_count($expression, '%s'), $subjectField)))->configureValueType(IntegerField::class));

		return $query->where('LEGAL_REGISTRY_ALLOWED', 1);
	}

	/**
	 * The placeholder is a subject ID expression supplied by an ORM ExpressionField.
	 * Alias edges are flattened by transferSubjects, so the root is one SQL lookup.
	 */
	public function subjectReadExpression(): string
	{
		if (!$this->canReadForm())
		{
			return '0';
		}
		$canonical = 'COALESCE((SELECT s.CANONICAL_ID FROM b_crm_legal_subject s WHERE s.ID = %s), %s)';
		$current = "SELECT l.ID FROM b_crm_legal_subject_link l WHERE l.SUBJECT_ID = $canonical AND l.IS_CURRENT = 'Y'";
		$leadSql = $this->allowedItemSql(SubjectReference::LEAD);
		$contactSql = $this->allowedItemSql(SubjectReference::CONTACT);
		$forbidden = "$current AND ((l.ENTITY_TYPE_ID = 1 AND l.ENTITY_ID NOT IN ($leadSql))"
			. " OR (l.ENTITY_TYPE_ID = 3 AND l.ENTITY_ID NOT IN ($contactSql)) OR l.ENTITY_TYPE_ID NOT IN (1,3))";
		$attached = "(EXISTS ($current) AND NOT EXISTS ($forbidden))";
		$detached = $this->permissions->isAdmin() ? " OR NOT EXISTS ($current)" : '';

		return "CASE WHEN ($attached$detached) THEN 1 ELSE 0 END";
	}

	/** Returns a SQL predicate with no IDs materialized in PHP. */
	private function allowedItemSql(int $type): string
	{
		$factory = Container::getInstance()->getFactory($type);
		if (!$factory)
		{
			return 'SELECT 0';
		}
		$helper = new PermissionEntityTypeHelper($type);
		$categories = $factory->isCategoriesSupported() ? $factory->getCategories() : [null];
		$queries = [];
		foreach ($categories as $category)
		{
			$filter = $category === null ? [] : ['=CATEGORY_ID' => $category->getId()];
			$filter = $this->permissions->itemsList()->applyAvailableItemsFilter(
				$filter,
				[$helper->getPermissionEntityTypeForCategory($category?->getId() ?? 0)],
				UserPermissions::OPERATION_READ,
			);
			$model = $factory->getDataClass();
			$queries[] = $model::query()->setSelect(['ID'])->setFilter($filter)->getQuery();
		}

		return $queries === [] ? 'SELECT 0' : implode(' UNION ', $queries);
	}
}
