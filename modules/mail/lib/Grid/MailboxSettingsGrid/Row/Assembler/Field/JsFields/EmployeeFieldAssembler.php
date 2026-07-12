<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;

class EmployeeFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'EmployeeField';

	private string $dataKey;

	public function __construct(array $columnIds, string $dataKey, Settings $settings)
	{
		parent::__construct($columnIds, $settings);
		$this->dataKey = $dataKey;
	}

	/**
	 * @return array{
	 *     id: int,
	 *     name: string,
	 *     avatar: array{
	 *         src: string,
	 *         width: int,
	 *         height: int,
	 *         size: int,
	 *     },
	 *     position: string,
	 *     pathToProfile: string,
	 * }
	 */
	protected function getRenderParams(array $rawValue): array
	{
		$userData = $rawValue[$this->dataKey] ?? [];
		$isOrphan = (bool)($rawValue['IS_ORPHAN'] ?? false);

		if (empty($userData))
		{
			if ($isOrphan)
			{
				return [
					[
						'name' => $rawValue['EMAIL'] ?? '',
						'position' => $this->getFiredBadge((string)($rawValue['OWNER_GENDER'] ?? '')),
					],
				];
			}

			return [
				[],
			];
		}

		if ($isOrphan)
		{
			$userData['position'] = $this->getFiredBadge((string)($rawValue['OWNER_GENDER'] ?? ''));
		}

		return [
			...$userData,
		];
	}

	private function getFiredBadge(string $gender): string
	{
		$suffix = $gender === 'F' ? '_F' : '_M';

		return (string)Loc::getMessage('MAIL_CLIENT_CONFIG_OWNER_BADGE_FIRED' . $suffix);
	}

	/**
	 * @param array $rawValue Raw row data with OWNER_DATA for the requester.
	 * @return string HTML rendered via parent JS extension (shows requester as employee).
	 */
	protected function prepareConnectionRequestPlaceholder(array $rawValue): string
	{
		return parent::prepareColumn(array_merge($rawValue, [
			'RENDER_AS_JS' => true,
		]));
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		$userData = $data[$this->dataKey] ?? [];

		return $userData['name'] ?? '';
	}
}
