<?php

declare(strict_types=1);

namespace Bitrix\AI\Entity;

use Bitrix\AI\Container;
use Bitrix\AI\Enum\RoleAvatarSize;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Model\EO_Role;
use Bitrix\AI\ShareRole\Service\RoleService;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;
use Bitrix\AiAssistant\Config\Feature;

class Role extends EO_Role
{
	use TranslateTrait;
	private const UNIVERSAL_ROLE_CODE = 'copilot_assistant';
	private const UNIVERSAL_ROLE_CIS_NAME = "BitrixGPT";
	private const BITRIXGPT_V2_AVATAR_PATH = '/bitrix/js/aiassistant/marta/image/bitrixgpt-icon-rounded.png';

	/**
	 * Return role name
	 *
	 * @return string
	 */
	public function getName(): string
	{
		if (($this->getCode() === self::UNIVERSAL_ROLE_CODE) && Bitrix24::isCisZone())
		{
			return self::UNIVERSAL_ROLE_CIS_NAME;
		}

		return $this->get('ROLE_TRANSLATE_NAME')?->getText() ?? $this->getDefaultName();
	}

	/**
	 * Return role name by langCode.
	 *
	 * @return array
	 */
	public function getAvatar(): array
	{
		if (($this->getCode() === self::UNIVERSAL_ROLE_CODE) && $this->isBitrixGptV2AvatarAvailable())
		{
			return [
				'small' => self::BITRIXGPT_V2_AVATAR_PATH,
				'medium' => self::BITRIXGPT_V2_AVATAR_PATH,
				'large' => self::BITRIXGPT_V2_AVATAR_PATH,
			];
		}

		$avatars = parent::getAvatar();

		if (isset($avatars['fileIds']))
		{
			$roleService = $this->getRoleService();

			return [
				'small' => $roleService->getAvatarLink($this->getId(), RoleAvatarSize::Small, $this->getHash()),
				'medium' => $roleService->getAvatarLink($this->getId(), RoleAvatarSize::Medium, $this->getHash()),
				'large' => $roleService->getAvatarLink($this->getId(), RoleAvatarSize::Large, $this->getHash()),
			];
		}

		if ($avatars === '')
		{
			return [
				'small' => '',
				'medium' => '',
				'large' => '',
			];
		}

		return $avatars;
	}

	/**
	 * Return role description
	 *
	 * @return string
	 */
	public function getDescription(): string
	{
		$description = $this->get('ROLE_TRANSLATE_DESCRIPTION')?->getText() ?? $this->getDefaultDescription();

		return $this->getIsSystem() ? $this->applyCopilotName($description) : $description;
	}

	private function applyCopilotName(string $text): string
	{
		if (!Loader::includeModule('ui'))
		{
			return $text;
		}

		return str_replace('CoPilot', (new CopilotNameService())->getCopilotName(), $text);
	}

	private function getRoleService(): RoleService
	{
		return Container::init()->getItem(RoleService::class);
	}

	private function isBitrixGptV2AvatarAvailable(): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		return Feature::getInstance()->isBitrixGptV2Available();
	}
}