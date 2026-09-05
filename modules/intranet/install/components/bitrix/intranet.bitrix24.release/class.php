<?php

use Bitrix\Intranet\Infrastructure\Update\Menu\SocialPresetMenuConverter;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Intranet\Integration\Templates;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class IntranetReleaseComponent extends \CBitrixComponent implements \Bitrix\Main\Engine\Contract\Controllerable
{
	protected string $id = 'cowork-code-2026';
	protected string $eastReleaseDate = '13.08.2026';
	protected int $campaignDurationDays = 7;
	protected int $sliderWidth = 1300;
	protected bool $showEar = false;
	protected bool $allowThemeChange = false;

	protected array $releaseMap = [
		'ru' => ['https://cowork-code.bitrix24.tech', '10:00'],
	];

	public function __construct($component = null)
	{
		parent::__construct($component);
	}

	public function executeComponent()
	{
		if (!ModuleManager::isModuleInstalled('bitrix24') || (defined('ERROR_404') && ERROR_404 ==='Y'))
		{
			return;
		}

		$this->arResult['show_time'] = false;
		$this->arResult['mode'] = '';

		if ($this->shouldShow())
		{
			$this->arResult['show_time'] = true;
			$this->arResult['options'] = $this->getOptions();

			// First set a new theme
			if ($this->getSliderModeCnt() === -1)
			{
				$this->incSliderModeCnt();
				if ($this->allowThemeChange && $this->setDefaultTheme())
				{
					if (Loader::includeModule('intranet'))
					{
						\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache();
					}

					LocalRedirect($GLOBALS['APPLICATION']->getCurUri());
				}
			}

			// Show Slider for the first hit
			if ($this->getSliderModeCnt() === 0)
			{
				$this->arResult['mode'] = 'slider';
				$this->incSliderModeCnt();
			}
			// else if ($this->getSliderModeCnt() === 1)
			// {
			// 	// Repeat after one day
			// 	$lastShowTime = $this->getLastShowTime();
			// 	if ((time() - $lastShowTime) > 24 * 3600)
			// 	{
			// 		$this->arResult['mode'] = 'slider';
			// 		$this->incSliderModeCnt();
			// 	}
			// }

			$this->includeComponentTemplate();
		}
	}

	protected function getZone(): ?string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return \CBitrix24::getPortalZone();
		}

		return null;
	}

	protected static function createDate(string $date): int
	{
		$time = \DateTime::createFromFormat('d.m.Y H:i', $date, new \DateTimeZone('Europe/Moscow'));

		return $time->getTimestamp();
	}

	protected function shouldShow(): bool
	{
		$release = $this->getRelease();
		if (!$release)
		{
			return false;
		}

		$now = time();
		$customDate = $this->getCustomReleaseDate();
		$startDate = $customDate === null ? static::createDate($release['releaseDate']) : $customDate;
		$campaignDurationSeconds = $this->campaignDurationDays * 24 * 3600;
		$endDate = $startDate + $campaignDurationSeconds;
		if ($now < $startDate || $now >= $endDate)
		{
			return false;
		}

		if (Loader::includeModule('extranet') && \CExtranet::isExtranetSite())
		{
			return false;
		}

		if ($this->isDeactivated())
		{
			return false;
		}

		if (Loader::includeModule('bitrix24'))
		{
			$creationTime = intval(\CBitrix24::getCreateTime());
			if ($creationTime === 0 || $creationTime > $startDate)
			{
				return false;
			}
		}

		$spotlight = new \Bitrix\Main\UI\Spotlight("release_{$this->id}");
		$spotlight->setUserTimeSpan($campaignDurationSeconds);
		$isAvailable = $spotlight->isAvailable();
		if (!$isAvailable)
		{
			$this->deactivate();
		}

		return $isAvailable;
	}

	protected function getOptions(): array
	{
		return [
			'url' => $this->getUrl(),
			'zone' => $this->getZone(),
			'id' => $this->id,
			'showEar' => $this->showEar,
			'sliderOptions' => [
				'width' => $this->sliderWidth,
			],
		];
	}

	protected function getRelease($zone = null): ?array
	{
		$zone = $zone === null ? $this->getZone() : $zone;
		if (!is_string($zone) || !array_key_exists($zone, $this->releaseMap))
		{
			return null;
		}

		$releaseTime = $this->releaseMap[$zone][1];
		$eastReleaseTime = $this->getEastReleaseTime();
		if ($eastReleaseTime !== null)
		{
			$releaseTime = $eastReleaseTime;
		}

		return [
			'zone' => $zone,
			'url' => $this->releaseMap[$zone][0],
			'releaseDate' => $this->eastReleaseDate . ' ' . $releaseTime,
		];
	}

	protected function getEastReleaseTime(): ?string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$license = \CBitrix24::getLicenseFamily();
		if (in_array($license, ['std', 'nfr']))
		{
			return '10:00';
		}
		elseif ($license === 'pro')
		{
			return '12:00';
		}
		elseif ($license === 'ent')
		{
			return '14:00';
		}
		elseif ($license === 'basic')
		{
			return '16:00';
		}

		return '18:00';
	}

	protected function getUrl(): string
	{
		$release = $this->getRelease();
		if (!$release)
		{
			return '';
		}

		$host = \Bitrix\Main\Context::getCurrent()->getRequest()->getHttpHost();
		$salt = md5('POLAR' . $host . 'STAR');
		if (Loader::includeModule('bitrix24'))
		{
			$salt =\CBitrix24::requestSign($salt);
		}

		$url = new \Bitrix\Main\Web\Uri($release['url']);
		$url->addParams([
			'host' => $host,
			'auth' => $salt,
		]);

		return $url->getUri();
	}

	public function closeAction()
	{
		// just for analytics
	}

	public function showAction()
	{
		$this->setLastShowTime();
	}

	public function deactivateAction()
	{
		$this->deactivate();
	}

	protected function getLastShowTimeOption(): string
	{
		return "release_{$this->id}:last_show_time";
	}

	protected function getSliderModeCntOption(): string
	{
		return "release_{$this->id}:slider_mode_cnt";
	}

	protected function getDeactivatedOption(): string
	{
		return "release_{$this->id}:deactivated";
	}

	protected function getStopChangeDefaultThemeOption(): string
	{
		return "release_{$this->id}:stop_change_default_theme";
	}

	protected function isDeactivated(): bool
	{
		return \CUserOptions::getOption('intranet', $this->getDeactivatedOption()) === 'Y';
	}

	protected function deactivate()
	{
		\CUserOptions::setOption('intranet', $this->getDeactivatedOption(), 'Y');
	}

	protected function activate()
	{
		\CUserOptions::deleteOption('intranet', $this->getDeactivatedOption());
	}

	protected function getLastShowTime(): ?int
	{
		$time = \CUserOptions::getOption('intranet', $this->getLastShowTimeOption(), null);

		return $time === null ? $time : (int)$time;
	}

	protected function setLastShowTime(): void
	{
		\CUserOptions::setOption('intranet', $this->getLastShowTimeOption(), time());
	}

	protected function shouldChangeDefaultTheme(): bool
	{
		return Option::get('intranet', $this->getStopChangeDefaultThemeOption(), 'N') !== 'Y';
	}

	protected function stopChangeDefaultTheme(): void
	{
		Option::set('intranet', $this->getStopChangeDefaultThemeOption(), 'Y');
	}

	protected function getCustomReleaseDate(): ?int
	{
		$date = Option::get('intranet', 'release:custom_date', null);
		if (is_string($date) && preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', $date))
		{
			$result = \DateTime::createFromFormat('d.m.Y H:i', $date, new \DateTimeZone('Europe/Moscow'));
			if ($result !== false)
			{
				return $result->getTimestamp();
			}
		}

		return null;
	}

	protected function getSliderModeCnt(): ?int
	{
		return (int)\CUserOptions::getOption('intranet', $this->getSliderModeCntOption(), -1);
	}

	protected function incSliderModeCnt(): void
	{
		\CUserOptions::setOption('intranet', $this->getSliderModeCntOption(), $this->getSliderModeCnt() + 1);
	}

	protected function setDefaultTheme(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		/*$newDefaultThemeId = (
			in_array($this->getZone(), ['ru', 'kz', 'by'])
				? 'light:gravity'
				: 'light:dark-silk'
		);*/

		$newDefaultThemeId = 'light:vibecode';

		$theme = new \Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker('bitrix24', 's1');

		$currentDefaultThemeId = $theme->getDefaultThemeId();
		$currentThemeId = $theme->getCurrentThemeId();

		// Try to change the default theme only for the first time
		if ($currentDefaultThemeId !== $newDefaultThemeId && !$theme->isCustomThemeId($currentDefaultThemeId))
		{
			if ($this->shouldChangeDefaultTheme())
			{
				$theme->setDefaultTheme($newDefaultThemeId);
			}
		}

		$this->stopChangeDefaultTheme();

		if ($currentThemeId !== $newDefaultThemeId && !$theme->isCustomThemeId($currentThemeId))
		{
			if ($currentDefaultThemeId !== $currentThemeId)
			{
				$theme->setCurrentThemeId($newDefaultThemeId);
			}

			return true;
		}

		return false;
	}

	public function configureActions()
	{
		// TODO: Implement configureActions() method.
	}
}
