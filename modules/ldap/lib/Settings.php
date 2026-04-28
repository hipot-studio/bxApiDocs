<?php

namespace Bitrix\Ldap;

use Bitrix\Main\Config\Option;

class Settings
{
	public function isLoginPrefixRequiredForNtlmAuth(): bool
	{
		return Option::get('ldap', 'ntlm_auth_without_prefix', 'Y') !== 'Y';
	}

	public function shouldCreateUserAfterFirstSuccessfulLogin(): bool
	{
		return Option::get('ldap', 'add_user_when_auth', 'Y') === 'Y';
	}

	public function isModernSyncEnabled(): bool
	{
		return Option::get('ldap', 'use_modern_sync', 'N') === 'Y';
	}

	public function enableModernSync(): void
	{
		Option::set('ldap', 'use_modern_sync', 'Y');
	}

	public function disableModernSync(): void
	{
		Option::set('ldap', 'use_modern_sync', 'N');
	}

	public function enableSyncLogger(string $fileName): void
	{
		Option::set('ldap', 'sync_logger_file', $fileName);
	}

	public function disableSyncLogger(): void
	{
		Option::delete('ldap', ['name' => 'sync_logger_file']);
	}

	public function getSyncLoggerFilePath(): string
	{
		return Option::get('ldap', 'sync_logger_file', '');
	}
}
