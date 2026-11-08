<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting\LiveFeed;

use Bitrix\Crm\Integration\Socialnetwork\Livefeed\AvailabilityHelper;
use Bitrix\Crm\Settings\LiveFeedSettings;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Service\Settings\AbstractSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingTitleProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * @extends AbstractSetting<bool>
 * @internal
 */
final class MergeEnabledSetting extends AbstractSetting
{
	public const CODE = 'crm.livefeed.merge.enabled';
	private const ERROR_CODE = 'SETTING_NOT_AVAILABLE';

	private readonly LiveFeedSettings $liveFeedSettings;

	public function __construct(?LiveFeedSettings $liveFeedSettings = null)
	{
		$this->liveFeedSettings = $liveFeedSettings ?? LiveFeedSettings::getCurrent();
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
		return $this->liveFeedSettings->isLiveFeedMergeEnabled();
	}

	protected function apply(mixed $value): Result
	{
		if (!$this->isWriteAvailable())
		{
			return (new Result())->addError(
				new Error('Setting is not available for writing.', self::ERROR_CODE),
			);
		}

		$this->liveFeedSettings->enableLiveFeedMerge($value);

		return new Result();
	}

	private function isWriteAvailable(): bool
	{
		return AvailabilityHelper::isAvailable();
	}
}
