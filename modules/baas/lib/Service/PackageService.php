<?php

namespace Bitrix\Baas\Service;

use Bitrix\Main;
use Bitrix\Baas;

class PackageService extends LocalService
{
	use Baas\Internal\Trait\SingletonConstructor;
	/**
	 * @var Baas\Entity\Package[]
	 */
	private array $repo = [];

	protected function __construct(
		protected Baas\Repository\PackageRepositoryInterface $packageRepository,
	)
	{
		parent::__construct();
	}

	public function getByCode(string $code, ?Baas\Model\EO_Package $data = null): ?Baas\Entity\Package
	{
		if (!array_key_exists($code, $this->repo))
		{
			if ($data || ($data = $this->packageRepository->findByCode($code)))
			{
				$this->repo[$code] = new Baas\Entity\Package($code, $data);
			}
		}

		return $this->repo[$code] ?? null;
	}

	public function getAll(): iterable
	{
		$collection = $this->packageRepository->getAll();

		foreach ($collection as $packageObject)
		{
			yield $this->getByCode($packageObject['CODE'], $packageObject);
		}
	}

	/**
	 * @param Baas\Entity\Service $service
	 * @return Baas\Entity\Package[]
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function getByService(Baas\Entity\Service $service): array
	{
		$collection = $this->packageRepository->getByService($service);

		$packages = [];
		foreach ($collection as $packageObject)
		{
			$packages[] = $this->getByCode($packageObject['CODE'], $packageObject);
		}

		return $packages;
	}

	public static function getInstance(): static
	{
		if (!isset(self::$instance))
		{
			self::$instance = new static(
				Baas\Repository\PackageRepository::getInstance(),
			);
		}

		return self::$instance;
	}
}
