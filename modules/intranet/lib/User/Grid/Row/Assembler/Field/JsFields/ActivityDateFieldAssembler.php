<?php

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field\JsFields;

use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Main\Context;
use Bitrix\Main\Grid\Settings;

/**
 * @method UserSettings getSettings()
 */
class ActivityDateFieldAssembler extends JsExtensionFieldAssembler
{
	private string $dateFormat;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);

		$culture = Context::getCurrent()->getCulture();

		$this->dateFormat =
			$culture->get('SHORT_DATE_FORMAT')
			. ', ' .
			$culture->get('SHORT_TIME_FORMAT')
		;
	}

	protected function getRenderParams($rawValue): array
	{
		return [
			'action' => $rawValue['CONFIRM_CODE'] !== '' && $rawValue['ACTIVE'] === 'N'
				? 'accept'
				: 'invite',
			'userId' => $rawValue['ID'],
			'isExtranet' => empty($rawValue['UF_DEPARTMENT']),
			'gridId' => $this->getSettings()->getID(),
		];
	}

	protected function getExtensionClassName(): string
	{
		return 'ActivityField';
	}

	protected function prepareColumnForExport($data): string
	{
		return $data['LAST_ACTIVITY_DATE'] ? FormatDateFromDB($data['LAST_ACTIVITY_DATE'], $this->dateFormat) : '';
	}

	protected function prepareColumn($value): mixed
	{
		if (!empty($value['CONFIRM_CODE']))
		{
			return parent::prepareColumn($value);
		}

		if ($value['LAST_ACTIVITY_DATE'])
		{
			return FormatDateFromDB($value['LAST_ACTIVITY_DATE'], $this->dateFormat);
		}

		return '';
	}
}