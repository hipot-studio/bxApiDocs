<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Markup\Form\FormProvisioner;
use Bitrix\Landing\Rights;

/**
 * Gives the form markers of the package their CRM forms before the site is created or the version is
 * applied: the bindings travel in the package to the intake, which writes them into the version manifest.
 * An edit finds the forms of the previous version in the block of the target page.
 *
 * Idempotent: a token the package has already bound keeps its form on a retry while the form exists.
 * A form that cannot be made never fails the generation, its token stays unbound and the agent gets a warning.
 */
class TaskProvisionMarkupForms extends MarkupTaskStep
{
	protected function doExecute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		$state = new MarkupSiteState($this->generation);
		$package = $state->getPackage();
		if (!$package)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No markup package was submitted.',
			);
		}

		$landing = $this->findTargetLanding();
		$result = $this->createProvisioner()->provision(
			$package,
			$landing ? $this->readManifest($landing) : null,
			$this->generation->getAuthorId(),
			$this->resolvePageTitle($package, $landing),
		);

		foreach ($result['warnings'] as $warning)
		{
			$state->addWarning($warning);
			$this->changed = true;
		}

		$known = [];
		foreach ($package->getFormDeclaration() as $form)
		{
			if (isset($form['binding']))
			{
				$known[$form['token']] = $form['binding'];
			}
		}
		$isRebound = false;
		foreach ($result['bindings'] as $token => $binding)
		{
			// a token of digits is an int key of the array
			$token = (string)$token;
			if (($known[$token] ?? null) !== $binding)
			{
				$package->bindForm($token, $binding['formId'], $binding['design']);
				$isRebound = true;
			}
		}
		// a retry may find the form of an earlier run gone and no new one made
		foreach (array_keys(array_diff_key($known, $result['bindings'])) as $token)
		{
			$package->unbindForm((string)$token);
			$isRebound = true;
		}

		if ($isRebound)
		{
			try
			{
				$state->replacePackage($package);
			}
			catch (\RuntimeException $exception)
			{
				throw new GenerationException(GenerationErrors::dataValidation, $exception->getMessage());
			}
			$this->changed = true;
		}

		return true;
	}

	protected function createProvisioner(): FormProvisioner
	{
		return new FormProvisioner();
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}

	/**
	 * The page an edit applies to; a creation has no page yet.
	 */
	private function findTargetLanding(): ?Landing
	{
		$landingId = (int)($this->siteData->getLandingId() ?? 0);
		if ($landingId <= 0)
		{
			return null;
		}

		$landing = $this->withoutRights(
			static fn(): Landing => Landing::createInstance($landingId, ['check_permissions' => false]),
		);

		return $landing->exist() ? $landing : null;
	}

	private function readManifest(Landing $landing): ?Markup\Manifest
	{
		$block = $this->withoutRights(static fn(): ?Block => Markup\Intake::findCustomBlock($landing));

		return $block ? Markup\Manifest::fromJson((string)($block->getMarkupManifest() ?? '')) : null;
	}

	private function resolvePageTitle(Markup\Package $package, ?Landing $landing): string
	{
		$title = trim((string)($package->getMeta()['title'] ?? ''));
		if ($title === '')
		{
			$title = trim($this->siteData->getPageMetaTitle());
		}
		if ($title === '' && $landing)
		{
			$title = trim((string)$landing->getTitle());
		}
		if ($title === '')
		{
			$title = trim($this->siteData->getSiteTitle());
		}

		return $title;
	}

	/**
	 * The step runs on behalf of the portal, in an agent as well: the page and its draft blocks are read
	 * with the rights off and in the edit mode, as the apply step does.
	 * @template T
	 * @param callable(): T $read
	 * @return T
	 */
	private function withoutRights(callable $read): mixed
	{
		$rightsBefore = Rights::isOn();
		$editModeBefore = Landing::getEditMode();
		Rights::setGlobalOff();
		Landing::setEditMode(true);
		try
		{
			return $read();
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
	}
}
