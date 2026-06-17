<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Builder\Structure\Filter\Column;

use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Column filter for node member roles.
 *
 * Filters NodeMember records by role XML ID (e.g. MEMBER_HEAD, MEMBER_DEPUTY_HEAD).
 * Use as part of {@see \Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter}.
 *
 * Usage:
 * ```
 * $filter = new NodeMemberFilter(
 *     entityIdFilter: EntityIdFilter::fromEntityId($userId),
 *     roleFilter: RoleFilter::fromRoles(
 *         NodeMemberRole::Head,
 *         NodeMemberRole::DeputyHead,
 *     ),
 * );
 * $members = (new NodeMemberDataBuilder())->addFilter($filter)->getAll();
 * ```
 */
final class RoleFilter extends BaseColumnFilter
{
	/**
	 * @param string[] $xmlIds Role XML IDs (e.g. MEMBER_HEAD, MEMBER_DEPUTY_HEAD)
	 */
	public function __construct(
		private array $xmlIds = [],
	)
	{}

	protected function getFieldName(): string
	{
		return 'ROLE.XML_ID';
	}

	protected function getItems(): array
	{
		return $this->xmlIds;
	}

	public static function fromRole(NodeMemberRole $role): self
	{
		return new self([$role->value]);
	}

	public static function fromRoles(NodeMemberRole ...$roles): self
	{
		return new self(
			array_map(
				static fn(NodeMemberRole $role): string => $role->value,
				$roles,
			),
		);
	}
}
