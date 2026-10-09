<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Security\AppUserProvider;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * User provider con identidad GLOBAL (ADR-0015).
 *
 * loadUserByIdentifier corre en el login (sin tenant en contexto) y
 * refreshUser corre en cada request autenticada (antes de que exista un
 * tenant activo en la sesión). Ambos deshabilitan el TenantFilter
 * alrededor de su lookup y lo restauran al salir.
 *
 * Lo que estos tests protegen es el cambio de modelo: el email es único
 * en todo el sistema, así que el usuario se resuelve SIN importar el
 * tenant activo de la sesión, y ya no existe ningún "claim" de tenant
 * en el usuario que validar.
 */
final class AppUserProviderTest extends IntegrationTestCase
{
    public function testLoadUserByIdentifierFindsUserWithTenantFilterEnabled(): void
    {
        ['user' => $user] = $this->seedUser('admin@example.com');

        // Filtro habilitado con un tenant SIN usuarios: si la carga
        // dependiera del filtro, el lookup fallaría con
        // UserNotFoundException.
        $filters = $this->em->getFilters();
        $filters->enable(TenantFilter::NAME);
        $filters->getFilter(TenantFilter::NAME)
            ->setParameter('tenantId', '999999', 'integer');

        $loaded = $this->provider()->loadUserByIdentifier('admin@example.com');

        $this->assertSame($user->getId(), $loaded->getId());
        $this->assertTrue(
            $filters->isEnabled(TenantFilter::NAME),
            'El TenantFilter debe quedar restaurado tras loadUserByIdentifier.'
        );
    }

    public function testLoadUserByIdentifierRejectsUnknownEmail(): void
    {
        $this->seedUser('admin@example.com');

        $this->expectException(UserNotFoundException::class);

        $this->provider()->loadUserByIdentifier('no-existe@example.com');
    }

    public function testRefreshUserReturnsSameUser(): void
    {
        ['user' => $user] = $this->seedUser('admin@example.com');

        $refreshed = $this->provider()->refreshUser($user);

        $this->assertSame($user->getId(), $refreshed->getId());
    }

    public function testRefreshUserRejectsUnknownUserAndRestoresFilter(): void
    {
        ['user' => $user] = $this->seedUser('admin@example.com');
        $id = $user->getId();

        // Borra el usuario de la BD; luego un objeto "fantasma" con su
        // id simula el usuario deserializado de una sesión cuya fila ya
        // no existe.
        $this->em->remove($user);
        $this->em->flush();

        $ghost = new AppUser();
        (new \ReflectionProperty(AppUser::class, 'id'))->setValue($ghost, $id);

        $filters = $this->em->getFilters();
        $filters->enable(TenantFilter::NAME);

        try {
            $this->provider()->refreshUser($ghost);
            $this->fail('Se esperaba UserNotFoundException para un usuario inexistente.');
        } catch (UserNotFoundException) {
            // esperado
        }

        $this->assertTrue(
            $filters->isEnabled(TenantFilter::NAME),
            'El TenantFilter debe quedar restaurado tras refreshUser.'
        );
    }

    private function provider(): AppUserProvider
    {
        return static::getContainer()->get(AppUserProvider::class);
    }

    /**
     * @return array{tenant: Tenant, user: AppUser}
     */
    private function seedUser(string $email): array
    {
        $tenant = $this->persistTenant('demo');

        $user = new AppUser();
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPasswordHash('hash-test');

        $this->em->persist($user);
        $this->em->flush();

        return ['tenant' => $tenant, 'user' => $user];
    }

    private function persistTenant(string $slug): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName(ucfirst($slug));
        $tenant->setSlug($slug);

        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
