<?php

namespace Bitrix\ImMobile\Marketplace;

use Bitrix\Im\V2\Marketplace\RegistrationValidator;

class Placement
{
	public const IMMOBILE_CONTEXT_MENU = 'IMMOBILE_CONTEXT_MENU';

	public static function getPlacementList(): array
	{
		return [
			self::IMMOBILE_CONTEXT_MENU,
		];
	}

	/**
	 * Event handler OnRestServiceBuildDescription of the Rest module
	 * @return array
	 */
	public static function onRestServiceBuildDescription(): array
	{
		return [
			\CRestUtil::GLOBAL_SCOPE => [
				\CRestUtil::PLACEMENTS => [
					self::IMMOBILE_CONTEXT_MENU => [
						'options' => [
							'extranet' => [
								'type' => 'string',
								'default' => 'N',
								'require' => false,
							],
							'context' => [
								'type' => 'string',
								'default' => 'ALL',
								'require' => false,
							],
							'role' => [
								'type' => 'string',
								'default' => 'USER',
								'require' => false,
							],
						],
						'registerCallback' => [
							'moduleId' => 'immobile',
							'callback' => [self::class, 'onRegisterPlacementContextMenu'],
						],
					],
				],
			],
		];
	}

	/**
	 * @see \Bitrix\Rest\Api\Placement::bind in section with $placementInfo['registerCallback']['callback']
	 * @param array $placementBind
	 * @param array $placementInfo
	 * @return array{error: ?string, error_description: ?string}
	 */
	public static function onRegisterPlacementContextMenu(array $placementBind, array $placementInfo): array
	{
		$result =
			RegistrationValidator::init($placementBind)
			->validateExtranet()
			->validateContext()
			->validateRole()
		;

		return $result->getResult();
	}
}
