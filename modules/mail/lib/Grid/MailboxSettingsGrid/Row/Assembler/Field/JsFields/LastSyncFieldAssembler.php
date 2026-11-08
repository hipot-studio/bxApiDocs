<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

class LastSyncFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'LastSyncField';

	/**
	 * @return array{
	 *     lastSync: ?int,
	 *     mailboxId: ?int,
	 *     migrationStatus: ?string,
	 *     migrationActive: bool,
	 *     migrationAdminProgressAvailable: bool,
	 *     migrationAdminProgressAllowed: bool,
	 *     migrationAdminProgressEarlyVisible: bool,
	 *     migrationAdminStatus: ?string,
	 * }
	 */
	protected function getRenderParams(array $rawValue): array
	{
		return [
			'lastSync' => $rawValue['LAST_ACTIVITY'] ?? null,
			'mailboxId' => $rawValue['ID'] ?? null,
			'hasError' => $rawValue['HAS_ERROR'] ?? null,
			'canEdit' => $rawValue['CAN_EDIT'] ?? false,
			'migrationStatus' => $rawValue['MIGRATION_STATUS'] ?? null,
			'migrationActive' => $rawValue['MIGRATION_ACTIVE'] ?? false,
			'migrationAdminProgressAvailable' => $rawValue['MIGRATION_ADMIN_PROGRESS_AVAILABLE'] ?? false,
			'migrationAdminProgressAllowed' => $rawValue['MIGRATION_ADMIN_PROGRESS_ALLOWED'] ?? false,
			'migrationAdminProgressEarlyVisible' => $rawValue['MIGRATION_ADMIN_PROGRESS_EARLY_VISIBLE'] ?? false,
			'migrationAdminStatus' => $rawValue['MIGRATION_ADMIN_STATUS'] ?? null,
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		return $data['LAST_ACTIVITY'] ?? '';
	}
}
