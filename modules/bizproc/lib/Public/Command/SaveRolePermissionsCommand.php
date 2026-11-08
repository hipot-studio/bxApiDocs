<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command;

use Bitrix\Bizproc\Internal\Service\RolePermissionService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

/**
 * Public write entry point for the role/permission matrix: the delegate scope validator and the transactional
 * delta/replace write live in {@see RolePermissionService}, so the invariant cannot be bypassed by a non-UI
 * path. Today the only caller is the ajax of the rights page; a channel added later builds this command too,
 * and it owns the question of whether it may express the "own templates" sentinel at all - the write itself
 * admits the value whatever the portal switch of the scope says.
 *
 * `$userGroups` is the FULL desired state of the delegate's area — a permission absent from a role's
 * `accessRights` is a removal inside that area, not "left untouched" (see the delta write). `$delegateId`
 * is the acting user whose committed area constrains the write.
 *
 * `$preserveInexpressibleValues` is the one documented exception to that, and it is off by default so the
 * general contract stays a full replace. A channel turns it on only when it cannot express every stored
 * value: today that is the rights page alone, whose static mode has no element for the "own templates"
 * sentinel, so a payload without it carries no intent of the user. A programmatic caller sending the full
 * state leaves it off and can therefore still revoke that value.
 *
 * It is a statement ABOUT THIS PAYLOAD and must be decided where the payload was built, not recomputed while
 * it is being written: the page ships its render-time answer along
 * ({@see \Bitrix\Bizproc\Public\Provider\PermissionMatrixProvider::SAVE_PARAM_OWN_SENTINEL_EXPRESSIBLE}).
 */
final class SaveRolePermissionsCommand extends AbstractCommand
{
	/**
	 * @param array<int, array{
	 *     id?: int|string,
	 *     title?: string,
	 *     accessRights?: array<int, array{id: int|string, value: int|string}>,
	 *     members?: array<string, mixed>,
	 *     accessCodes?: array<string, mixed>,
	 * }> $userGroups
	 * @param array<int, int|string> $deletedUserGroups
	 */
	public function __construct(
		public readonly int $delegateId,
		public readonly array $userGroups,
		public readonly array $deletedUserGroups = [],
		public readonly bool $preserveInexpressibleValues = false,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'delegateId' => $this->delegateId,
			'userGroups' => $this->userGroups,
			'deletedUserGroups' => $this->deletedUserGroups,
			'preserveInexpressibleValues' => $this->preserveInexpressibleValues,
		];
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			delegateId: (int)($props['delegateId'] ?? 0),
			userGroups: (array)($props['userGroups'] ?? []),
			deletedUserGroups: (array)($props['deletedUserGroups'] ?? []),
			preserveInexpressibleValues: (bool)($props['preserveInexpressibleValues'] ?? false),
		);
	}

	protected function execute(): Result
	{
		return (new RolePermissionService())->saveRolePermissions(
			$this->delegateId,
			$this->userGroups,
			$this->deletedUserGroups,
			$this->preserveInexpressibleValues,
		);
	}
}
