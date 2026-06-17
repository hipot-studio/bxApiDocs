<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Settings;

use Bitrix\Main\Grid\Settings;

class PasswordlessRequestsSettings extends Settings
{
	private const DEFAULT_EXTENSION_NAME = 'Mail.PasswordlessRequestsGrid';
	private const DEFAULT_EXTENSION_LOAD_NAME = 'mail.grid.passwordless-requests-grid';

	private string $extensionName;
	private string $extensionLoadName;

	/**
	 * @param array{
	 *     ID?: string,
	 *     extensionName?: string,
	 *     extensionLoadName?: string,
	 * } $params
	 */
	public function __construct(array $params)
	{
		parent::__construct($params);

		$this->extensionName = $params['extensionName'] ?? self::DEFAULT_EXTENSION_NAME;
		$this->extensionLoadName = $params['extensionLoadName'] ?? self::DEFAULT_EXTENSION_LOAD_NAME;
	}

	public function getExtensionName(): string
	{
		return $this->extensionName;
	}

	public function getExtensionLoadName(): string
	{
		return $this->extensionLoadName;
	}
}
