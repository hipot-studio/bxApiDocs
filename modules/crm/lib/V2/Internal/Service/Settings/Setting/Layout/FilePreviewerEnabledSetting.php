<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Layout;

use Bitrix\Crm\Settings\LayoutSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class FilePreviewerEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.layout.file_previewer.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';
	private const WRITE_AVAILABILITY_OPTION = 'allow_disable_file_previewer_in_kanban_and_grid';

	private readonly LayoutSettings $layoutSettings;

	public function __construct(?LayoutSettings $layoutSettings = null)
	{
		$this->layoutSettings = $layoutSettings ?? LayoutSettings::getCurrent();
	}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function getDescription(): SettingDescription
	{
		return new SettingDescription(
			self::CODE,
			SettingTitleProvider::getTitle(self::CODE),
			new BooleanSchema(),
			SettingTitleProvider::getHint(self::CODE),
		);
	}

	public function getValue(): bool
	{
		return $this->layoutSettings->isFilePreviewerInKanbanAndGridEnabled();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isWriteAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->layoutSettings->enableFilePreviewerInKanbanAndGrid($value);

		return new Result();
	}

	private function isWriteAvailable(): bool
	{
		return Option::get('crm', self::WRITE_AVAILABILITY_OPTION, 'N') === 'Y';
	}
}
