<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias;

use Bitrix\Sign\Type\BlockParty;
use Bitrix\Sign\Type\Member\Role as MemberRole;

class AliasRoleResolver
{
	public function getShortRoleNameByContext(AliasContext $context, ?int $party = null): string
	{
		$isPartyMismatch = $party !== null && $context->party !== null && $context->party !== $party;
		if ($context->role === null || $isPartyMismatch)
		{
			return 'Unknown';
		}

		return $this->getShortRoleNameByMemberRole($context->role) ?? 'Unknown';
	}

	public function getPartyByRoleNameAndContext(AliasContext $context, string $roleName): ?int
	{
		if ($context->role === null)
		{
			return null;
		}

		$shortRoleName = $this->getShortRoleNameByMemberRole($context->role);
		$fullRoleName = $this->getFullRoleNameByMemberRole($context->role);
		if ($shortRoleName !== $roleName && $fullRoleName !== $roleName && $context->role !== $roleName)
		{
			return null;
		}

		return $context->party ?? $this->resolvePartyByRole($context->role);
	}

	public function resolveContextByAlias(AliasContext $context, string $alias): AliasContext
	{
		$roleName = $this->extractRoleNameFromAlias($alias);
		if ($roleName === null)
		{
			return $context;
		}

		$role = $this->resolveMemberRoleByAliasRoleName($roleName);
		if ($role === null)
		{
			return $context;
		}

		return $context->with(
			party: $this->resolvePartyByRole($role),
			role: $role,
		);
	}

	public function resolveMemberRoleByAliasRoleName(string $roleName): ?string
	{
		return match ($roleName)
		{
			MemberRole::ASSIGNEE, 'Assignee', 'Rep' => MemberRole::ASSIGNEE,
			MemberRole::SIGNER, 'Employee', 'Emp' => MemberRole::SIGNER,
			default => null,
		};
	}

	public function resolveMemberRoleByParty(int $party): ?string
	{
		return match ($party)
		{
			BlockParty::NOT_LAST_PARTY => MemberRole::ASSIGNEE,
			BlockParty::LAST_PARTY => MemberRole::SIGNER,
			default => null,
		};
	}

	public function getShortRoleNameByMemberRole(string $role): ?string
	{
		return match ($role)
		{
			MemberRole::ASSIGNEE => 'Rep',
			MemberRole::SIGNER => 'Emp',
			default => null,
		};
	}

	public function getFullRoleNameByMemberRole(string $role): ?string
	{
		return match ($role)
		{
			MemberRole::ASSIGNEE => 'Assignee',
			MemberRole::SIGNER => 'Employee',
			default => null,
		};
	}

	public function resolvePartyByRole(string $role): ?int
	{
		return match ($role)
		{
			MemberRole::ASSIGNEE => BlockParty::NOT_LAST_PARTY,
			MemberRole::SIGNER => BlockParty::LAST_PARTY,
			default => null,
		};
	}

	private function extractRoleNameFromAlias(string $alias): ?string
	{
		$parts = explode('.', $alias);
		if (count($parts) !== FieldNameTransformer::FIELD_PARTS_COUNT || $parts[1] === '')
		{
			return null;
		}

		return $parts[1];
	}
}
