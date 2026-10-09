<?php

declare(strict_types=1);

namespace App\Tests\Functional\Customer;

use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;

final class CustomerCrudTest extends FunctionalTestCase
{
    public function testCreateCustomerFlow(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/clientes/nueva');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'María Pérez',
                'phone' => '600111222',
                'email' => 'maria@example.com',
                'address' => 'Calle Mayor 1',
                'notes' => 'Cliente habitual',
                'isActive' => 1,
            ],
        ]);

        $this->assertResponseRedirects('/clientes');
        $this->em->clear();

        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => 'maria@example.com']);
        $this->assertNotNull($customer);
        $this->assertSame('María Pérez', $customer->getName());
        $this->assertSame('600111222', $customer->getPhone());
        $this->assertSame('Calle Mayor 1', $customer->getAddress());
        $this->assertSame('Cliente habitual', $customer->getNotes());
        $this->assertTrue($customer->isActive());
        $this->assertSame($tenant->getId(), $customer->getTenant()->getId());
    }

    public function testCreateRejectsMissingName(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/clientes/nueva');
        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => '',
                'email' => 'sin-nombre@example.com',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El nombre es obligatorio.');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Customer::class)->findOneBy(['email' => 'sin-nombre@example.com']));
    }

    public function testCreateRejectsInvalidEmail(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/clientes/nueva');
        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'Email inválido',
                'email' => 'no-es-un-email',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'Introduce una dirección de email válida.');
        $this->em->clear();
        $this->assertCount(0, $this->em->getRepository(Customer::class)->findAll());
    }

    public function testCreateRejectsDuplicatePhoneInSameTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/nueva');
        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'María Duplicada',
                'phone' => '600111222',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El teléfono "600111222" ya está en uso en este negocio.');

        $this->em->clear();
        $customers = $this->em->getRepository(Customer::class)->findBy(['phone' => '600111222']);
        $this->assertCount(1, $customers);
        $this->assertSame('María Pérez', $customers[0]->getName());
    }

    public function testCreateRejectsDuplicateEmailInSameTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/nueva');
        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'María Duplicada',
                'email' => 'maria@example.com',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El email "maria@example.com" ya está en uso en este negocio.');

        $this->em->clear();
        $this->assertCount(1, $this->em->getRepository(Customer::class)->findAll());
    }

    public function testCreateAllowsSamePhoneAndEmailInAnotherTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $foreign = $this->seedForeignTenant();
        $this->seedForeignCustomer($foreign['tenant']);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/nueva');
        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'María repetida en el demo',
                'phone' => '600111222',
                'email' => 'maria@example.com',
            ],
        ]);

        $this->assertResponseRedirects('/clientes');
        $this->em->clear();
        $demoCustomers = $this->em->getRepository(Customer::class)->findBy(['tenant' => $tenant]);
        $this->assertCount(1, $demoCustomers);
        $this->assertSame('600111222', $demoCustomers[0]->getPhone());
        $this->assertSame('maria@example.com', $demoCustomers[0]->getEmail());
    }

    public function testCreateStoresOptionalFieldsAsNullAndAllowsRepeatedNulls(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'Sin contacto', null, null);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/nueva');
        $form = $crawler->selectButton('Crear cliente')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'Otro sin contacto',
                'phone' => '',
                'email' => '',
                'address' => '',
                'notes' => '',
            ],
        ]);

        $this->assertResponseRedirects('/clientes');
        $this->em->clear();

        $created = $this->em->getRepository(Customer::class)->findOneBy(['name' => 'Otro sin contacto']);
        $this->assertNotNull($created);
        $this->assertNull($created->getPhone());
        $this->assertNull($created->getEmail());
        $this->assertNull($created->getAddress());
        $this->assertNull($created->getNotes());
        $this->assertCount(2, $this->em->getRepository(Customer::class)->findAll());
    }

    public function testEditCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/'.$customer->getId().'/editar');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar cambios')->form();
        $client->submit($form, [
            'customer_form' => [
                'name' => 'María Pérez García',
                'phone' => '600111222',
                'email' => 'maria.garcia@example.com',
                'address' => 'Avenida del Mar 5',
                'notes' => '',
                'isActive' => 1,
            ],
        ]);

        $this->assertResponseRedirects('/clientes');
        $this->em->clear();

        $reloaded = $this->em->getRepository(Customer::class)->find($customer->getId());
        $this->assertSame('María Pérez García', $reloaded->getName());
        $this->assertSame('600111222', $reloaded->getPhone());
        $this->assertSame('maria.garcia@example.com', $reloaded->getEmail());
        $this->assertSame('Avenida del Mar 5', $reloaded->getAddress());
    }

    public function testEditForeignCustomerReturns404(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $foreign = $this->seedForeignTenant();
        $foreignCustomer = $this->seedForeignCustomer($foreign['tenant']);

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/clientes/'.$foreignCustomer->getId().'/editar');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeactivateCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes');
        $form = $crawler->filter('form[action*="/desactivar"]')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/clientes');
        $this->em->clear();

        $reloaded = $this->em->getRepository(Customer::class)->find($customer->getId());
        $this->assertFalse($reloaded->isActive());

        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'desactivado');
    }

    public function testDeactivateRejectsInvalidCsrfToken(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/clientes/'.$customer->getId().'/desactivar', ['_csrf_token' => 'no-es-un-token']);

        $this->assertResponseRedirects('/clientes');
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'Solicitud inválida.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Customer::class)->find($customer->getId());
        $this->assertTrue($reloaded->isActive());
    }

    private function seedForeignCustomer(Tenant $tenant): Customer
    {
        $customer = new Customer();
        $customer->setTenant($tenant);
        $customer->setName('Cliente ajeno');
        $customer->setPhone('600111222');
        $customer->setEmail('maria@example.com');

        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    /**
     * @return array{tenant: Tenant, user: AppUser}
     */
    private function seedForeignTenant(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Ajeno');
        $tenant->setSlug('ajeno');

        $user = new AppUser();
        $user->setEmail('admin@ajeno.local');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPasswordHash('x');

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->flush();

        return ['tenant' => $tenant, 'user' => $user];
    }
}
