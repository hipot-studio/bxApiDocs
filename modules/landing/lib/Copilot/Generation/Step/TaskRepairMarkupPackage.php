<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Connector\AI\IConnector;
use Bitrix\Landing\Copilot\Connector\AI\MarkupRepairNode;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Markup;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Markup\Form\ForeignFormCutter;
use Bitrix\Landing\Markup\Package;
use Bitrix\Landing\Markup\Repair\PackageJsonSchema;
use Bitrix\Landing\Markup\Repair\RepairNodePrompt;
use Bitrix\Landing\Markup\Repair\RepairableCodes;
use Bitrix\Landing\Markup\Security\ForbiddenCodeRules;

/**
 * The repair cycle of the intake gate, owned by the portal (ADR R10): when the gate
 * leaves only repairable semantic codes, the package and the codes go to the
 * External/AiNode repairer and the answer is re-run through the gate. At most two
 * iterations per submission; a schema-invalid or still-failing answer counts as a
 * failed iteration; after the budget is spent the generation fails through the
 * existing error channel, so an edited page keeps its current version untouched.
 * The only exception is a package whose sole defect is a foreign form: the forms are
 * cut out, the agent gets a warning and the scenario goes on.
 *
 * The step is re-entrant: the first pass sends the node request and stays unfinished,
 * the aiassistant webhook events resume the generation (EventHandler), and the next
 * pass processes the persisted answer. A package with no repairable code passes the
 * step untouched.
 */
class TaskRepairMarkupPackage extends MarkupTaskStep
{
	public const MAX_ITERATIONS = 2;

	private bool $waiting = false;

	public function isFinished(): bool
	{
		return !$this->waiting;
	}

	protected function doExecute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		$this->waiting = false;

		$state = new MarkupSiteState($this->generation);
		if ($state->isRepairResolved())
		{
			return true;
		}

		$pending = $this->getPendingRequest();
		if ($pending === null)
		{
			if ($state->getRepairIteration() > 0)
			{
				// a sent request disappeared (cleanup, expired rows): treat as a failed iteration
				$this->continueOrFail($state);

				return true;
			}

			$package = $this->getPackage($state);
			$errors = $this->collectRepairableErrors($package);
			if ($errors === [])
			{
				return true;
			}
			$this->sendToNode($state, $package, $errors);

			return true;
		}

		if (!$pending->isReceived())
		{
			if (!$pending->isTimeIsOver())
			{
				$this->waiting = true;

				return true;
			}
			$pending->saveError(Error::createError(Errors::requestTimeout));
		}

		$repaired = $this->buildRepairedPackage($pending, $state);
		$pending->setApplied();
		if ($repaired !== null)
		{
			// the repaired package lands in the upload buffer of the generation, the state keeps references
			try
			{
				$state->replacePackage($repaired);
			}
			catch (\RuntimeException $exception)
			{
				throw new GenerationException(GenerationErrors::dataValidation, $exception->getMessage());
			}
			$state->markRepairResolved();
			$this->changed = true;
			$this->getLog()->addResponse($this->stepId, 'Markup repair succeeded', [
				'iteration' => $state->getRepairIteration(),
			]);

			return true;
		}

		$this->continueOrFail($state);

		return true;
	}

	protected function createConnector(): IConnector
	{
		return new MarkupRepairNode();
	}

	/**
	 * Semantic (repairable) codes of the current package in the DTO-05 shape.
	 * Hard codes are not collected here: they stop the scenario earlier, in the
	 * accept step.
	 * @return list<array{code: string, path: string|null, line: int|null, rule: string|null, details: string}>
	 */
	protected function collectRepairableErrors(Package $package): array
	{
		$result = (new Markup\Validator($this->getLog()))->validate($package);

		$errors = [];
		foreach ($result->getErrors() as $error)
		{
			if (!RepairableCodes::isRepairable((string)$error->getCode()))
			{
				continue;
			}
			$customData = $error->getCustomData();
			$customData = is_array($customData) ? $customData : [];
			$line = $customData['line'] ?? null;
			$rule = $customData['rule'] ?? null;
			$errors[] = [
				'code' => (string)$error->getCode(),
				'path' => $customData['path'] ?? null,
				// the place and the rule of a static analysis hit: the prompt repairs by them
				'line' => $line === null ? null : (int)$line,
				'rule' => $rule === null ? null : (string)$rule,
				'details' => $error->getMessage(),
			];
		}

		return $errors;
	}

	private function continueOrFail(MarkupSiteState $state): void
	{
		if ($state->getRepairIteration() >= self::MAX_ITERATIONS)
		{
			if ($this->cutForeignForms($state))
			{
				return;
			}

			throw new GenerationException(
				GenerationErrors::dataValidation,
				'The markup package could not be repaired automatically. '
				. 'Fix the reported files in the package and submit it again.',
			);
		}

		$package = $this->getPackage($state);
		$errors = $this->collectRepairableErrors($package);
		if ($errors === [])
		{
			// nothing left to repair: the stored package already passes the gate
			$state->markRepairResolved();
			$this->changed = true;

			return;
		}
		$this->sendToNode($state, $package, $errors);
	}

	/**
	 * The fallback of a spent budget: when foreign forms are the only defect left, they are
	 * cut out instead of failing the generation. Every file was referenced before the cut, so a file
	 * the gate finds unreferenced after it belonged to the cut forms alone and goes with them.
	 * Anything else left after the cut keeps the failure.
	 */
	private function cutForeignForms(MarkupSiteState $state): bool
	{
		$package = $this->getPackage($state);
		$errors = $this->collectRepairableErrors($package);
		if ($errors === [])
		{
			return false;
		}
		foreach ($errors as $error)
		{
			if (
				$error['code'] !== RepairableCodes::REPAIRABLE_CODE
				|| ($error['rule'] ?? null) !== ForbiddenCodeRules::RULE_FOREIGN_FORM
			)
			{
				return false;
			}
		}

		$cut = (new ForeignFormCutter())->cut($package);
		if ($cut['removed'] === 0)
		{
			return false;
		}
		$cutPackage = $cut['package'];
		$removedFiles = $this->removeOrphanFiles($cutPackage);
		if ($removedFiles === null)
		{
			return false;
		}

		try
		{
			$state->replacePackage($cutPackage);
		}
		catch (\RuntimeException $exception)
		{
			throw new GenerationException(GenerationErrors::dataValidation, $exception->getMessage());
		}
		$state->markRepairResolved();
		$state->addWarning([
			'code' => ForeignFormCutter::WARNING_REMOVED,
			'token' => '',
			'details' => ['removed' => $cut['removed']],
		]);
		$this->changed = true;
		$this->getLog()->addResponse($this->stepId, 'Markup repair exhausted, foreign forms cut out', [
			'removed' => $cut['removed'],
			'removedFiles' => $removedFiles,
		]);

		return true;
	}

	/**
	 * Removes the files left unreferenced, those referenced by a removed file too.
	 * @return list<string>|null Paths of the removed files, null when a defect of another kind is left.
	 */
	private function removeOrphanFiles(Package $package): ?array
	{
		$removedFiles = [];
		$errors = $this->collectRepairableErrors($package);
		while ($errors !== [])
		{
			$assetCount = count($package->getAssets());
			foreach ($errors as $error)
			{
				if ($error['code'] !== RepairableCodes::UNREFERENCED_FILE || !is_string($error['path']))
				{
					return null;
				}
				$package->removeAsset($error['path']);
				$removedFiles[] = $error['path'];
			}
			if (count($package->getAssets()) === $assetCount)
			{
				return null;
			}
			$errors = $this->collectRepairableErrors($package);
		}

		return $removedFiles;
	}

	private function sendToNode(MarkupSiteState $state, Package $package, array $errors): void
	{
		$iteration = $state->getRepairIteration() + 1;
		$state->setRepairIteration($iteration);
		$this->changed = true;

		$prompt = (new Prompt(RepairNodePrompt::NODE_NAME))
			->setMarkers([
				'payload' => [
					'package' => $this->exportPackageForNode($package),
					'errors' => $errors,
				],
				'userId' => $this->generation->getAuthorId(),
			])
		;

		$request = new Request((int)$this->generation->getId(), (int)$this->stepId);
		if (!$request->send($prompt, $this->createConnector()))
		{
			throw new GenerationException(
				GenerationErrors::notSendRequest,
				'The markup repair node request was not sent.',
			);
		}

		$this->getLog()->addRequest($this->stepId, 'Markup repair node call, iteration ' . $iteration, [
			'errors' => $errors,
		]);
		$this->waiting = true;
	}

	/**
	 * The package in the contract form the node expects: text files by content,
	 * binary files by path and hash only - the answer restores them byte-for-byte
	 * from this very package.
	 */
	private function exportPackageForNode(Package $package): array
	{
		$validator = new Markup\Validator();

		$files = [];
		foreach ($package->getAssets() as $asset)
		{
			$path = (string)$asset['path'];
			$kind = $validator->resolveTypeByPath($path) ?? 'binary';
			$file = [
				'path' => $path,
				'kind' => $kind,
				'hash' => Markup\FileStore::hash((string)$asset['content']),
			];
			if ($kind === 'text')
			{
				$file['content'] = (string)$asset['content'];
			}
			$files[] = $file;
		}

		$forms = [];
		foreach ($package->getFormDeclaration() as $form)
		{
			// the binding of the portal is not a part of the declaration the node works with
			unset($form['binding']);
			$forms[] = $form;
		}

		return [
			'entry' => $package->getEntry(),
			'files' => $files,
			'images' => $package->getImageDeclaration(),
			'forms' => $forms,
			'meta' => $package->getMeta(),
		];
	}

	private function buildRepairedPackage(Request $request, MarkupSiteState $state): ?Package
	{
		$error = $request->getError();
		if ($error !== null)
		{
			$this->getLog()->addResponse($this->stepId, 'Markup repair node error', $error->toArray());

			return null;
		}

		$answer = $request->getResult()['output'] ?? null;
		$package = (new PackageJsonSchema())->buildPackage($answer, $this->getPackage($state));
		if ($package === null)
		{
			$this->getLog()->addResponse($this->stepId, 'Markup repair answer rejected by the schema', null);

			return null;
		}

		$validation = (new Markup\Validator($this->getLog()))->validate($package);
		if (!$validation->isSuccess() || $this->collectRepairableErrors($package) !== [])
		{
			$this->getLog()->addResponse(
				$this->stepId,
				'Markup repair answer rejected by the gate',
				$validation->getErrorMessages(),
			);

			return null;
		}

		return $package;
	}

	private function getPackage(MarkupSiteState $state): Package
	{
		$package = $state->getPackage();
		if (!$package)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No markup package was submitted.',
			);
		}

		return $package;
	}

	private function getPendingRequest(): ?Request
	{
		$pending = null;
		foreach (Request::getByGeneration((int)$this->generation->getId(), (int)$this->stepId) as $request)
		{
			if (
				!$request->isApplied()
				&& ($pending === null || (int)$request->getId() > (int)$pending->getId())
			)
			{
				$pending = $request;
			}
		}

		return $pending;
	}

	private function getLog(): Log
	{
		return new Log($this->generation->getId());
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}
}
