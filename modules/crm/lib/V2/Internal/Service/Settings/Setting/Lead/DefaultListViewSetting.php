<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\Lead;

use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<int>
 * @internal
 */
final class DefaultListViewSetting extends AbstractSetting
{
	public const ID = 'crm.lead.default_list_view';

	private readonly LeadSettings $leadSettings;

	public function __construct(?LeadSettings $leadSettings = null)
	{
		$this->leadSettings = $leadSettings ?? LeadSettings::getCurrent();
	}

	public function getId(): string
	{
		return self::ID;
	}

	public function getDescription(): SettingDescription
	{
		$options = [];
		foreach ($this->leadSettings::getViewDescriptions() as $value => $title)
		{
			$options[] = new SettingOption((int)$value, $title);
		}

		return new SettingDescription(
			self::ID,
			SettingTitleProvider::getTitle(self::ID),
			new SelectSchema(...$options),
			SettingTitleProvider::getDescription(self::ID),
			SettingTitleProvider::getNote(self::ID),
			'lead_default_list_view',
		);
	}

	public function getValue(): int
	{
		return $this->leadSettings->getDefaultListViewID();
	}

	protected function apply(mixed $value): Result
	{
		$this->leadSettings->setDefaultListViewID($value);

		return new Result();
	}
}
