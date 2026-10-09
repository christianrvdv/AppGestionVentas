<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Repositorio de membresías (ADR-0015, Fase 3.5).
 *
 * El selector de negocio lee de acá con el TenantFilter DESHABILITADO
 * a propósito: hay que ver todos los negocios del usuario, incluido el
 * activo (que el filtro excluiría). Estos tests fijan ese contrato:
 * orden estable, lookup por (membresía, usuario) y neutralización del
 * filtro sin dejarlo apagado.
 */
final class TenantMembershipRepositoryTest extends IntegrationTestCase
{
    private TenantMembershipRepositoryInterface $memberships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberships = static::getContainer()->get(TenantMembershipRepositoryInterface::class);
    }

    public function testFindByUserOrdersDefaultFirstThenOldest(): void
    {
        $user = $this->createAppUser($this->createTenant('principal'), 'ana@demo.local');
        $this->createMembership($user, $this->createTenant('segundo'), joinedAt: '-1 month');
        $this->createMembership($user, $this->createTenant('tercero'), joinedAt: '-1 day');

        $this->em->clear();

        $found = $this->memberships->findByUser($this->loadUser($user->getId()));

        $this->assertSame(
            ['principal', 'segundo', 'tercero'],
            array_map(static fn (TenantMembership $m): string => $m->getTenant()->getSlug(), $found),
            'El negocio por defecto va primero; el resto, por antigüedad.'
        );
    }

    public function testFindByUserReturnsMembershipsAcrossTenants(): void
    {
        $user = $this->createAppUser($this->createTenant('acme'), 'ana@demo.local');
        $this->createMembership($user, $this->createTenant('globant'));

        $otherUser = $this->createAppUser($this->createTenant('ajeno'), 'otra@demo.local');

        $this->em->clear();

        $found = $this->memberships->findByUser($this->loadUser($user->getId()));

        $this->assertCount(2, $found, 'El selector debe listar TODOS los negocios del usuario.');
        $this->assertNotSame(
            $user->getId(),
            $otherUser->getId(),
            'Precondición: hay dos usuarios distintos en la BD.'
        );
    }

    public function testFindByUserReturnsEmptyArrayForUserWithoutMemberships(): void
    {
        $user = new AppUser();
        $user->setEmail('sin-negocio@demo.local');
        $user->setPasswordHash('hash-test');
        $this->em->persist($user);
        $this->em->flush();

        $this->assertSame([], $this->memberships->findByUser($user));
    }

    public function testFindByIdAndUserResolvesOwnMembership(): void
    {
        $user = $this->createAppUser($this->createTenant('acme'), 'ana@demo.local');
        $membershipId = $user->getMemberships()->first()->getId();

        $this->em->clear();

        $membership = $this->memberships->findByIdAndUser($membershipId, $user->getId());

        $this->assertNotNull($membership);
        $this->assertSame($membershipId, $membership->getId());
    }

    public function testFindByIdAndUserRejectsMembershipOfAnotherUser(): void
    {
        $owner = $this->createAppUser($this->createTenant('acme'), 'ana@demo.local');
        $foreignMembershipId = $owner->getMemberships()->first()->getId();

        $intruder = $this->createAppUser($this->createTenant('intruso'), 'intruso@demo.local');

        $this->em->clear();

        $this->assertNull(
            $this->memberships->findByIdAndUser($foreignMembershipId, $intruder->getId()),
            'Una membresía ajena no debe resolverse: el usuario va en la query.'
        );
    }

    public function testLookupsWorkWithTenantFilterEnabledAndRestoreIt(): void
    {
        $user = $this->createAppUser($this->createTenant('acme'), 'ana@demo.local');
        $membershipId = $user->getMemberships()->first()->getId();

        // El filtro activo apunta a OTRO tenant: sin TenantFilterGuard,
        // estas consultas devolverían vacío (o excluirían el negocio
        // activo), que es justamente lo que rompería el selector.
        $filters = $this->em->getFilters();
        $filters->enable(TenantFilter::NAME);
        $filters->getFilter(TenantFilter::NAME)->setParameter('tenantId', '999999', 'integer');

        try {
            $this->assertCount(1, $this->memberships->findByUser($user));
            $this->assertNotNull($this->memberships->findByIdAndUser($membershipId, $user->getId()));

            $this->assertTrue(
                $filters->isEnabled(TenantFilter::NAME),
                'El TenantFilter debe quedar restaurado tras cada lookup.'
            );
        } finally {
            $filters->disable(TenantFilter::NAME);
        }
    }

    private function loadUser(int $id): AppUser
    {
        $user = $this->em->getRepository(AppUser::class)->find($id);
        \assert($user instanceof AppUser);

        return $user;
    }

    private function createMembership(
        AppUser $user,
        Tenant $tenant,
        string $joinedAt = 'now',
        bool $isDefault = false,
    ): TenantMembership {
        $membership = new TenantMembership($user, $tenant);
        $membership->setRole(TenantMembership::ROLE_USER);
        $membership->setIsDefault($isDefault);

        // joinedAt se setea en el constructor; para que el orden por
        // antigüedad sea determinístico se fija a mano.
        (new \ReflectionProperty(TenantMembership::class, 'joinedAt'))->setValue(
            $membership,
            new \DateTimeImmutable($joinedAt)
        );

        $user->addMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }
}
