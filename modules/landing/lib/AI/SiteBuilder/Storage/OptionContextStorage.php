<?php
namespace Bitrix\Landing\AI\SiteBuilder\Storage;

use Bitrix\Landing\AI\SiteBuilder\Dto\DevContextDto;
use Bitrix\Main\Config\Option;

final class OptionContextStorage implements ContextStorageInterface
{
	private const DEFAULT_MODULE_ID = 'landing';
	private const DEFAULT_SITE_OPTION = 'landing_ai_blocks_site_id';
	private const DEFAULT_LANDING_OPTION = 'landing_ai_blocks_landing_id';

	private string $moduleId;
	private string $siteOptionName;
	private string $landingOptionName;

	public function __construct(
		string $moduleId = self::DEFAULT_MODULE_ID,
		string $siteOptionName = self::DEFAULT_SITE_OPTION,
		string $landingOptionName = self::DEFAULT_LANDING_OPTION
	)
	{
		$this->moduleId = $moduleId;
		$this->siteOptionName = $siteOptionName;
		$this->landingOptionName = $landingOptionName;
	}

	public function load(): DevContextDto
	{
		return new DevContextDto(
			(int)Option::get($this->moduleId, $this->siteOptionName, 0),
			(int)Option::get($this->moduleId, $this->landingOptionName, 0)
		);
	}

	public function save(DevContextDto $context): void
	{
		Option::set($this->moduleId, $this->siteOptionName, $context->getSiteId());
		Option::set($this->moduleId, $this->landingOptionName, $context->getLandingId());
	}

	public function reset(): void
	{
		$this->save(new DevContextDto());
	}
}
