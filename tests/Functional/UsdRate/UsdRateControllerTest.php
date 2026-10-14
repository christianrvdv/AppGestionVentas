<?php

declare(strict_types=1);

namespace App\Tests\Functional\UsdRate;

use App\Entity\UsdRate;
use App\Tests\Functional\FunctionalTestCase;

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
}
