<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Service\Providers\ProfileProvider;
use Bitrix\Sign\Type\BlockCode;

class UserFieldsPlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	public const USER_FIELD_CODE_PREFIX = 'USER_';
	private readonly FieldService $fieldService;
	private readonly ProfileProvider $profileProvider;

	public function __construct(
		?FieldService $fieldService = null,
		?ProfileProvider $profileProvider = null,
	)
	{
		$container = \Bitrix\Sign\Service\Container::instance();
		$this->fieldService = $fieldService ?? $container->getDocumentFieldService();
		$this->profileProvider = $profileProvider ?? $container->getServiceProfileProvider();
	}


	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		$fieldDescription = $this->profileProvider->getDescriptionByFieldName($fieldCode);
		$fieldType = $this->fieldService->convertUserFieldType($fieldDescription['type'] ?? '');
		$fieldCode = self::USER_FIELD_CODE_PREFIX . $fieldCode;
		$blockCode = $party === 1
			? BlockCode::B2E_MY_REFERENCE
			: BlockCode::B2E_REFERENCE
		;

		return NameHelper::create(
			$blockCode,
			$fieldType,
			$party,
			$fieldCode,
		);
	}
}
