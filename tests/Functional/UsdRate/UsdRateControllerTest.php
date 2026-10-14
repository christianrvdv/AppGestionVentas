<?php

declare(strict_types=1);

namespace App\Tests\Functional\UsdRate;

use App\Entity\UsdRate;
use App\Repository\Contract\AppSettingRepositoryInterface;
use App\Repository\Contract\UsdRateRepositoryInterface;
use App\Repository\TenantRepository;
use App\Service\SettingsService;
use App\Service\TenantContext;
use App\Service\UsdRateService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class UsdRateControllerTest extends FunctionalTestCase
{
    /**
     * Regresión del TypeError: NumberType entrega int|float (el view
     * transformer devuelve int|float|null, nunca string), pero UsdRateService
     * exige string para no perder precisión. Sin normalizar, el camino feliz
     * reventaba con 500.
     */
    public function testNewPersistsRateAsFourDecimalString(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/tasas-usd/nueva');
        $client->submit($crawler->selectButton('Guardar tasa')->form(), [
            'usd_rate_form' => [
                'rateDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'rate' => 780.5,
            ],
        ]);

        $this->assertResponseRedirects('/tasas-usd');

        $this->em->clear();
        $rate = $this->em->getRepository(UsdRate::class)->findOneBy([], ['id' => 'DESC']);
        $this->assertNotNull($rate);
        $this->assertSame('780.5000', $rate->getRate());
    }

    /**
     * Regresión del camino Turbo: si el dominio lanza, el form sigue siendo
     * VÁLIDO (no hay constraint violada), así que render() con un FormInterface
     * devolvería 200 y Turbo exigiría un redirect. El controller convierte la
     * excepción en FormError para que el form quede inválido y Symfony fije 422.
     *
     * El lanzamiento se provoca sustituyendo el servicio en el contenedor de
     * test: una fecha duplicada NO lanza (desde ADR-0004 crea una corrección),
     * y el resto de las \LogicException de setRate() solo ocurren con un tenant
     * corrupto, inalcanzable vía HTTP.
     */
    public function testNewDomainFailureReturns422WithDomainMessage(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $this->replaceUsdRateServiceWithFailure('Tenant demo caído.');

        $crawler = $client->request('GET', '/tasas-usd/nueva');
        $this->assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Guardar tasa')->form(), [
            'usd_rate_form' => [
                'rateDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'rate' => 780.5,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('[role="alert"]', 'Tenant demo caído.');
    }

    /**
     * Sustituye UsdRateService por una instancia cuyo repositorio lanza la
     * excepción de dominio. El servicio es privado pero lo inyectan seis
     * consumidores, así que no se inlinea y TestContainer puede reemplazarlo
     * antes de que el controller lo resuelva en el request.
     */
    private function replaceUsdRateServiceWithFailure(string $message): void
    {
        $repository = new class($message) implements UsdRateRepositoryInterface {
            public function __construct(private readonly string $message)
            {
            }

            public function findCurrent(int $tenantId): ?UsdRate
            {
                return null;
            }

            public function findCurrentBefore(int $tenantId, \DateTimeImmutable $date): ?UsdRate
            {
                return null;
            }

            public function findEffectiveByDate(int $tenantId, \DateTimeImmutable $date): ?UsdRate
            {
                throw new \LogicException($this->message);
            }

            public function findByDateRange(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return [];
            }

            public function createPaginatedQueryBuilder(int $tenantId): \Doctrine\ORM\QueryBuilder
            {
                throw new \LogicException('No usado en este test.');
            }
        };

        $container = static::getContainer();

        $service = new UsdRateService(
            $repository,
            self::serviceOfType($container->get(TenantRepository::class), TenantRepository::class),
            self::serviceOfType($container->get(TenantContext::class), TenantContext::class),
            self::serviceOfType($container->get(SettingsService::class), SettingsService::class),
            self::serviceOfType($container->get(AppSettingRepositoryInterface::class), AppSettingRepositoryInterface::class),
            self::serviceOfType($this->em, EntityManagerInterface::class),
        );

        $container->set(UsdRateService::class, $service);
    }

    /**
     * El contenedor de test devuelve object|null; el tipo real ya está
     * garantizado por el class-string con el que se pidió.
     *
     * @template T of object
     * @param object|null $service
     * @param class-string<T> $class
     * @return T
     */
    private static function serviceOfType(object|null $service, string $class): object
    {
        if (!$service instanceof $class) {
            throw new \LogicException(sprintf('Se esperaba el servicio %s.', $class));
        }

        return $service;
    }
}
