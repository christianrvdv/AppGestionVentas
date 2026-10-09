<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Security\AppUserProvider;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Aislamiento multi-tenant del user provider (ADR-0009).
 *
 * loadUserByIdentifier corre en el login (sin tenant en contexto) y
 * refreshUser corre en cada request autenticado. Ambos deshabilitan el
 * TenantFilter alrededor de su lookup — así que la defensa contra una
 * sesión manipulada es la comparación EXPLÍCITA del tenant persistido
 * contra el tenant que declara el usuario de la sesión, más la
 * restauración del filtro al salir (finally).
 */
final class AppUserProviderTest extends IntegrationTestCase
{
    public function testLoadUserByIdentifierRestoresTenantFilterAfterLookup(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->seedUser('admin@example.com');

        $filters = $this->em->getFilters();
        $filters->enable(TenantFilter::NAME);

        static::getContainer()->get(RequestStack::class)->push(
            Request::create('/login', 'POST', ['tenant_slug' => $tenant->getSlug()])
        );

        $loaded = $this->provider()->loadUserByIdentifier('admin@example.com');

        $this->assertSame($user->getId(), $loaded->getId());
        $this->assertTrue(
            $filters->isEnabled(TenantFilter::NAME),
            'El TenantFilter debe quedar restaurado tras loadUserByIdentifier.'
        );
    }

    public function testRefreshUserRejectsSessionUserWhosePersistedTenantDiffers(): void
    {
        ['user' => $user] = $this->seedUser('admin@example.com');
        $otherTenant = $this->persistTenant('otro');

        // Simula una sesión con un usuario cuyo tenant EN MEMORIA (B) no
        // coincide con el tenant realmente persistido (A): así se
        // reproduce una sesión manipulada o desactualizada.
        $sessionUser = clone $user;
        $sessionUser->setTenant($otherTenant);

        $this->expectException(UnsupportedUserException::class);

        $this->provider()->refreshUser($sessionUser);
    }

    public function testRefreshUserReturnsSameUserWhenTenantMatches(): void
    {
        ['user' => $user] = $this->seedUser('admin@example.com');

        $refreshed = $this->provider()->refreshUser($user);

        $this->assertSame($user->getId(), $refreshed->getId());
    }

    public function testRefreshUserRejectsUnknownUserAndRestoresFilter(): void
    {
        ['user' => $user] = $this->seedUser('admin@example.com');
        $id = $user->getId();
        $tenant = $user->getTenant();

        // Borra el usuario de la BD; luego un objeto "fantasma" con su
        // id simula el usuario deserializado de una sesión cuya fila ya
        // no existe.
        $this->em->remove($user);
        $this->em->flush();

        $ghost = new AppUser();
        (new \ReflectionProperty(AppUser::class, 'id'))->setValue($ghost, $id);
        $ghost->setTenant($tenant);

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
        $user->setTenant($tenant);
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
