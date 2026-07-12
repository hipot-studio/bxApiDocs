<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Socialnetwork\Collab;

use Bitrix\Im\V2\Common\SingletonTrait;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Promotion\Entity\Promotion;
use Bitrix\Im\V2\Promotion\Entity\PromotionList;
use Bitrix\Im\V2\Promotion\Internals\DeviceType;
use Bitrix\Im\V2\Promotion\Internals\PromotionType;
use Bitrix\Im\V2\Promotion\Service\PromotionServiceInterface;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Promotion\ProjectAi;

class ProjectAiPromotionService implements PromotionServiceInterface
{
	use SingletonTrait;

	private const PROMO_ID = 'socialnetwork:collab-project-ai:28042026:all';

	/**
	 * @throws LoaderException
	 */
	public function getActive(DeviceType $type = DeviceType::ALL): PromotionList
	{
		$promotions = new PromotionList();

		if ($type === DeviceType::MOBILE)
		{
			return $promotions;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return $promotions;
		}

		if (!Collab::isNewProjectsAvailable())
		{
			return $promotions;
		}

		$userId = (int)User::getCurrent()->getId();
		if ($userId <= 0)
		{
			return $promotions;
		}

		if (!(new ProjectAi())->shouldShow($userId))
		{
			return $promotions;
		}

		$promotions->add(new Promotion(self::PROMO_ID));

		return $promotions;
	}

	public function getPromotionType(): PromotionType
	{
		return PromotionType::COLLAB;
	}

	public function isCurrentTypePromotion(string $promotionId): bool
	{
		return $promotionId === self::PROMO_ID;
	}

	public function markAsViewed(Promotion $promotion): Result
	{
		return new Result();
	}
}
