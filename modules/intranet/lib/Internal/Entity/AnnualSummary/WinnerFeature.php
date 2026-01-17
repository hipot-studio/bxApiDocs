<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

use Bitrix\Intranet\Internal\Integration\Main\AnnualSummarySign;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Contract\Arrayable;

class WinnerFeature implements Arrayable, EntityInterface
{
	public function __construct(
		private readonly ?Feature $feature,
	) {
	}

	/**
	 * @throws ArgumentTypeException
	 */
	public function toArray(): array
	{
		$feature = $this->feature?->toArray() ?? [];
		$feature['id'] = $this->getId();
		$feature['featureId'] = $this->getFeatureId();
		$feature['signedId'] = $this->signedId();
		$feature['message'] = $this->getMessage();

		return $feature;
	}

	public function getId(): string
	{
		return 'winner_' . $this->getFeatureId();
	}

	private function getFeatureId(): string
	{
		return $this->feature?->getId()?->value ?? 'base';
	}

	/**
	 * @throws ArgumentTypeException
	 */
	public function signedId(): string
	{
		$signer = new AnnualSummarySign();

		return $signer->sign($this->getId());
	}

	public function getMessage(): array
	{
		Loc::loadMessages(__DIR__ . '/Feature.php');
		$feature = mb_strtoupper($this->getFeatureId());

		return [
			'title' => Loc::getMessage("INTRANET_ANNUAL_SUMMARY_CARD_TOP_{$feature}_TITLE") ?? '',
			'name' => Loc::getMessage("INTRANET_ANNUAL_SUMMARY_CARD_TOP_{$feature}_NAME") ?? '',
			'description' => Loc::getMessage("INTRANET_ANNUAL_SUMMARY_CARD_TOP_{$feature}_DESCRIPTION" ?? ''),
		];
	}
}
