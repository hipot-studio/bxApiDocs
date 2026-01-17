<?php

namespace Bitrix\Intranet\Internal\Provider\AnnualSummary;

use Bitrix\Intranet\Component\UserProfile;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Main\AnnualSummarySign;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\User;
use Bitrix\Main\Web\Uri;

class FeatureProvider
{
	public function getTop($count = 5): array
	{
		$currentUser = CurrentUser::get();
		$storage = new AnnualSummaryRepository($currentUser->getId());
		$annualSummary = $storage->load();
		$top = $annualSummary->getTop($count);
		$signer = new AnnualSummarySign();
		$signedUserId = $signer->sign($currentUser->getId());
		$userpicPath = UserProfile::getUserPhoto($currentUser->getPersonalPhotoId(), 84);

		return [
			'topFeatures' => $storage->has() ? $top->toArray() : [],
			'options' => [
				'signedUserId' => $signedUserId,
				'userpicPath' => Uri::urnEncode((string)$userpicPath),
			],
		];
	}

	public function getShared(string $userId, string $type): array
	{
		$storage = new AnnualSummaryRepository($userId);
		$user = (new User($userId));
		$annualSummary = $storage->load();
		$feature = $annualSummary->getTop()->filterById($type)->getIterator()->current();
		$photoId = (int)$user->getFields()['PERSONAL_PHOTO'] ?? 0;

		if ($photoId)
		{
			$userpicPath = UserProfile::getUserPhoto($photoId, 84);
		}

		return [
			'feature' => $storage->has() ? $feature?->toArray() : null,
			'options' => [
				'canClose' => false,
				'isShowStartView' => false,
				'hideBtn' => true,
				'showProgressBar' => false,
				'userpicPath' => Uri::urnEncode((string)($userpicPath ?? '')),
				'showOverlay' => false,
			],
		];
	}
}
