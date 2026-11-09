<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Rights;
use Bitrix\Main\Result;

/**
 * Applies the submitted package as the new version of the existing custom block, with the
 * outcomes of the image step: the generated pictures become the files of their tokens, the
 * summary of the images goes to the state for the answers of the tools.
 * The intake service owns the version change: files, manifest and the history step
 * (undo works). Files of the previous version are not unbound - the history step
 * holds them. Idempotent: a retry re-applies the same version, hash deduplication
 * stores no new bytes.
 *
 * A build the file store refused keeps the code of the refusal in the generation: the error text of
 * the step is free, the code is the contract of the failure answer (ADR § 3.5).
 */
class TaskApplyMarkupPackage extends MarkupTaskStep
{
	public const BUILD_REFUSAL_KEY = 'markup_build_refusal';

	private const BUILD_REFUSAL_CODES = [
		Markup\FileStore::ERROR_ASSET_NOT_STORED,
		Markup\FileStore::ERROR_DISK_QUOTA,
	];

	/**
	 * Remembers the refusal code of the file store from the result of a build, or forgets the code of
	 * an earlier attempt when this build has none.
	 */
	public static function rememberBuildRefusal(Generation $generation, Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			if (in_array($code, self::BUILD_REFUSAL_CODES, true))
			{
				$generation->setData(self::BUILD_REFUSAL_KEY, ['code' => $code]);

				return;
			}
		}

		$generation->deleteData(self::BUILD_REFUSAL_KEY);
	}

	/**
	 * Refusal code of the file store that stopped the build, null when the build was not refused by it.
	 */
	public static function getBuildRefusalCode(Generation $generation): ?string
	{
		$state = $generation->getData(self::BUILD_REFUSAL_KEY);
		$code = is_array($state) ? (string)($state['code'] ?? '') : '';

		return in_array($code, self::BUILD_REFUSAL_CODES, true) ? $code : null;
	}

	protected function doExecute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		$landingId = (int)($this->siteData->getLandingId() ?? 0);
		if ($landingId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Landing is missing for markup package apply.',
			);
		}

		$state = new MarkupSiteState($this->generation);
		$package = $state->getPackage();
		if (!$package)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No markup package was submitted.',
			);
		}

		$rightsBefore = Rights::isOn();
		$editModeBefore = Landing::getEditMode();
		Rights::setGlobalOff();
		Landing::setEditMode(true);
		try
		{
			$landing = Landing::createInstance($landingId, ['check_permissions' => false]);
			if (!$landing->exist())
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					'Landing not found for markup package apply.',
				);
			}

			$block = Markup\Intake::findCustomBlock($landing);
			if (!$block)
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					'The target page has no custom markup block.',
				);
			}

			$result = $this->createIntake()->apply($package, $block, $landingId, $state->getImageOutcomes());
			self::rememberBuildRefusal($this->generation, $result);
			if (!$result->isSuccess())
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					implode('; ', $result->getErrorMessages()),
				);
			}
			$state->setImageSummary((array)($result->getData()['images'] ?? []));
			$state->setBlockId((int)$block->getId());

			$landing->touch();
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}

		return true;
	}

	protected function createIntake(): Markup\Intake
	{
		return new Markup\Intake();
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}
}
