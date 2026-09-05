<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Provider;

use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Vibe\Provider\VibeContextDto;
use Bitrix\Landing\Vibe\Facade\Portal;
use Bitrix\Main\Loader;
use Bitrix\UI\Form\FormProvider;
use Bitrix\UI\Form\UrlProvider;

abstract class AbstractVibeProvider
{
	public const DEFAULT_LIMIT_CODE = 'limit_office_vibe';
	protected const DEFAULT_SORT = 100;

	private VibeContextDto $context;

	public function __construct(VibeContextDto $context)
	{
		$this->context = $context;
	}

	public function getContext(): VibeContextDto
	{
		return $this->context;
	}

	/**
	 * Main title.
	 * Used on the vibe settings page and (if not overridden) as the title of
	 * the public vibe page.
	 * @return string
	 */
	abstract public function getTitle(): string;

	/**
	 * Allows customizing the public vibe page title.
	 * If not set, getTitle() will use
	 *
	 * @return string|null
	 */
	public function getViewTitle(): ?string
	{
		return null;
	}

	/**
	 * Provider can check some conditions to match to see if it is available
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		return true;
	}

	public function getSort(): int
	{
		return static::DEFAULT_SORT;
	}

	public function isMainVibe(): bool
	{
		return false;
	}

	/**
	 * Icon for section in portal settings.
	 * @return IconDto|null - If not set - will use default icon.
	 *
	 */
	public function getIcon(): ?IconDto
	{
		return null;
	}

	// region Access
	public function canEdit(): bool
	{
		$checker = new Portal();

		return
			$this->canView()
			&& ($checker->isIntranetAdmin() || $this->hasExplicitVibeRight(Rights::ADDITIONAL_RIGHTS['admin']))
			&& $checker->checkFeature(Portal::VIBE_FEATURE)
		;
	}

	public function canCreate(): bool
	{
		if ($this->canEdit())
		{
			return true;
		}

		$checker = new Portal();

		return
			$this->canView()
			&& $this->hasExplicitVibeRight(Rights::ADDITIONAL_RIGHTS['create'])
			&& $checker->checkFeature(Portal::VIBE_FEATURE)
		;
	}

	/**
	 * Explicitly granted vibe right of the current user (strict mode).
	 * With the landing permissions feature off hasAdditionalRight() allows any
	 * registry code to everyone, so the feature gates the whole rights channel:
	 * without it only the intranet administrator keeps the access.
	 * @param string $code Code from Rights::ADDITIONAL_RIGHTS.
	 * @return bool
	 */
	protected function hasExplicitVibeRight(string $code): bool
	{
		return
			Manager::checkFeature(Manager::FEATURE_PERMISSIONS_AVAILABLE)
			&& Rights::hasAdditionalRight($code, Type::SCOPE_CODE_VIBE, false, true)
		;
	}

	public function canView(): bool
	{
		$checker = new Portal();

		if (!$checker->isIntranet())
		{
			return false;
		}

		if (!$checker->isIntranetUser())
		{
			return false;
		}

		if ($checker->isExtranetUser())
		{
			return false;
		}

		return true;
	}

	public function getLimitCode(): string
	{
		return self::DEFAULT_LIMIT_CODE;
	}
	// endregion

	// region Publication
	/**
	 * @return string
	 */
	abstract public function getUrlPublic(): string;

	/**
	 * Check that the vibe has been published
	 * @return bool
	 */
	abstract public function isPublished(): bool;

	/**
	 * Call, when pressed Publication button
	 */
	abstract public function onPublish(): void;

	/**
	 * Call, when pressed UnPublication button
	 */
	abstract public function onWithdraw(): void;
	// endregion

	/**
	 * Special params for feedback form
	 * @return array|null
	 */
	public function getFeedbackParams(): ?array
	{
		if (!Loader::includeModule('ui'))
		{
			return null;
		}

		return [
			'id' => 'mainpage_feedback',
			'forms' => (new FormProvider())->getPartnerFormList(),
			'presets' => [
				'source' => 'landing',
			],
			'portalUri' => (new UrlProvider())->getPartnerPortalUrl(),
		];
	}

}