<?php

namespace Bitrix\Crm\Feature\Entity;

use Bitrix\Crm\Feature\AbstractCrmFlag;
use Bitrix\Crm\Feature\BaseFeature;
use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Common;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Factory;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Type\Collection;

class FeatureRepository
{
	private ?array $featureClassesById = null;
	private ?Factory $flagFactory = null;

	public function getAllByCategory(): array
	{
		$result = [];
		foreach ($this->getFeatureClassesById() as $id => $className)
		{
			$feature = $this->resolveFeature($className, $id);
			if ($feature === null)
			{
				continue;
			}

			$category = $this->readSafely(
				$id,
				'getCategory',
				static fn(): BaseCategory => $feature->getCategory(),
				Common::getInstance(),
			);
			$categoryId = $this->getShortClassName($category::class);

			if (!isset($result[$categoryId]))
			{
				$result[$categoryId] = [
					'name' => $this->readSafely(
						$id,
						'getCategoryName',
						static fn(): string => $category->getName(),
						$categoryId,
					),
					'sort' => $this->readSafely(
						$id,
						'getCategorySort',
						static fn(): int => $category->getSort(),
						0,
					),
					'items' => [],
				];
			}

			$secretLinkAllowed = $this->readSafely(
				$id,
				'allowSwitchBySecretLink',
				static fn(): bool => $feature->allowSwitchBySecretLink(),
				false,
			);
			$secretLink = null;
			if ($secretLinkAllowed)
			{
				$secretLink = UrlManager::getInstance()->getHostUrl()
					. '/crm/configs/?enableFeature=' . urlencode($id)
				;
			}

			$result[$categoryId]['items'][] = [
				'name' => $this->readSafely(
					$id,
					'getName',
					static fn(): string => $feature->getName(),
					$id,
				),
				'id' => $id,
				'sort' => $this->readSafely(
					$id,
					'getSort',
					static fn(): int => $feature->getSort(),
					100,
				),
				'enabled' => $this->readSafely(
					$id,
					'isEnabled',
					fn(): bool => $this->readState($className),
					false,
				),
				'secretLink' => $secretLink,
			];
		}

		Collection::sortByColumn($result, 'sort');
		$result = array_values($result);
		foreach ($result as &$category)
		{
			Collection::sortByColumn($category['items'], 'sort');
		}
		unset($category);

		return $result;
	}

	public function has(string $id): bool
	{
		return isset($this->getFeatureClassesById()[$id]);
	}

	public function isEnabled(string $id): bool
	{
		$className = $this->getFeatureClassById($id);

		return $className === null ? false : $this->readState($className);
	}

	public function enable(string $id): void
	{
		$className = $this->getFeatureClassById($id);
		if ($className !== null)
		{
			$this->setState($className, true);
		}
	}

	public function disable(string $id): void
	{
		$className = $this->getFeatureClassById($id);
		if ($className !== null)
		{
			$this->setState($className, false);
		}
	}

	public function isSwitchBySecretLinkAllowed(string $id): bool
	{
		$className = $this->getFeatureClassById($id);
		if ($className === null)
		{
			return false;
		}

		$feature = $this->resolveFeature($className, $id);
		if ($feature === null)
		{
			return false;
		}

		return $this->readSafely(
			$id,
			'allowSwitchBySecretLink',
			static fn(): bool => $feature->allowSwitchBySecretLink(),
			false,
		);
	}

	public function isFlagClass(string $className): bool
	{
		return class_exists($className)
			&& (
				is_subclass_of($className, BaseFeature::class)
				|| is_subclass_of($className, AbstractCrmFlag::class)
			)
		;
	}

	public function isEnabledByClass(string $className): bool
	{
		return $this->isFlagClass($className) ? $this->readState($className) : false;
	}

	public function enableByClass(string $className): void
	{
		if ($this->isFlagClass($className))
		{
			$this->setState($className, true);
		}
	}

	public function disableByClass(string $className): void
	{
		if ($this->isFlagClass($className))
		{
			$this->setState($className, false);
		}
	}

	protected function getAllFeaturesClasses(): array
	{
		$result = [];
		foreach ($this->getFeatureFiles() as $fileName)
		{
			$shortClassName = $this->getClassNameByFileName($fileName);
			if ($shortClassName === null)
			{
				continue;
			}

			$className = 'Bitrix\\Crm\\Feature\\' . $shortClassName;
			$isSwitchableFlag = $this->readSafely(
				$shortClassName,
				'collect',
				fn(): bool => $this->isFlagClass($className) && !(new \ReflectionClass($className))->isAbstract(),
				false,
			);
			if ($isSwitchableFlag)
			{
				$result[] = $className;
			}
		}

		return $result;
	}

	protected function getClassNameByFileName(string $fileName): ?string
	{
		return preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.php$/D', $fileName, $matches) === 1
			? $matches[1]
			: null
		;
	}

	protected function getFeatureFiles(): array
	{
		$files = scandir(Path::normalize(__DIR__ . '/../'));

		return $files === false ? [] : $files;
	}

	private function getFeatureClassesById(): array
	{
		if ($this->featureClassesById === null)
		{
			$this->featureClassesById = [];
			foreach ($this->getAllFeaturesClasses() as $className)
			{
				$this->featureClassesById[$this->getShortClassName($className)] = $className;
			}
		}

		return $this->featureClassesById;
	}

	private function getFeatureClassById(string $id): ?string
	{
		return $this->getFeatureClassesById()[$id] ?? null;
	}

	private function resolveFeature(string $className, string $id): BaseFeature|AbstractCrmFlag|null
	{
		if (is_subclass_of($className, BaseFeature::class))
		{
			try
			{
				return new $className();
			}
			catch (\Throwable $exception)
			{
				$this->logFailure($id, 'instantiate', $exception);

				return null;
			}
		}

		try
		{
			$feature = $this->getFlagFactory()->resolveFlag($className);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($id, 'resolveFlag', $exception);

			return null;
		}
		if ($feature instanceof AbstractCrmFlag)
		{
			return $feature;
		}

		$this->logFailure(
			$id,
			'resolveFlag',
			new \RuntimeException("Cannot resolve CRM feature flag {$className}"),
		);

		return null;
	}

	private function readState(string $className): bool
	{
		if (is_subclass_of($className, BaseFeature::class))
		{
			return (new $className())->isEnabled();
		}

		return Feature::isEnabled($className);
	}

	private function setState(string $className, bool $enabled): void
	{
		if (is_subclass_of($className, BaseFeature::class))
		{
			$feature = new $className();
			if ($enabled)
			{
				$feature->enable();
			}
			else
			{
				$feature->disable();
			}

			return;
		}

		if ($enabled)
		{
			Feature::enable($className);
		}
		else
		{
			Feature::disable($className);
		}
	}

	private function getFlagFactory(): Factory
	{
		$this->flagFactory ??= new Factory();

		return $this->flagFactory;
	}

	private function readSafely(
		string $featureId,
		string $operation,
		callable $reader,
		mixed $fallback,
	): mixed
	{
		try
		{
			return $reader();
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($featureId, $operation, $exception);

			return $fallback;
		}
	}

	private function logFailure(string $featureId, string $operation, \Throwable $exception): void
	{
		Container::getInstance()->getLogger('Features')->error(
			'Cannot read CRM feature data',
			[
				'featureId' => $featureId,
				'operation' => $operation,
				'exception' => $exception,
			],
		);
	}

	private function getShortClassName(string $className): string
	{
		$separatorPosition = strrpos($className, '\\');

		return $separatorPosition === false
			? $className
			: substr($className, $separatorPosition + 1)
		;
	}
}
