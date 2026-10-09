<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Usuario administrador del tenant Demo.
 *
 * Credenciales de desarrollo: admin@demo.local / demo
 * (hasheadas con el password hasher real de la aplicación).
 *
 * El usuario es GLOBAL (ADR-0015): no tiene columna de tenant. La
 * pertenencia al negocio Demo vive en la TenantMembership (ROLE_ADMIN,
 * negocio por defecto), que es lo que permite loguearse sin escribir un
 * slug y que la sesión termine con el tenant Demo activo.
 */
final class AppUserFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public const REF_ADMIN = 'demo_admin';
    public const REF_MEMBERSHIP = 'demo_admin_membership';
    public const EMAIL = 'admin@demo.local';
    public const PASSWORD = 'demo';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $tenant = $this->getReference(TenantFixture::REF_DEMO, Tenant::class);

        $user = new AppUser();
        $user->setEmail(self::EMAIL);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setIsActive(true);
        $user->setPasswordHash(
            $this->passwordHasher->hashPassword($user, self::PASSWORD)
        );

        $membership = new TenantMembership($user, $tenant);
        $membership->setRole(TenantMembership::ROLE_ADMIN);
        $membership->setIsDefault(true);
        $user->addMembership($membership);

        $manager->persist($user);
        $manager->persist($membership);
        $manager->flush();

        $this->addReference(self::REF_ADMIN, $user);
        $this->addReference(self::REF_MEMBERSHIP, $membership);
    }

    /**
     * @return string[]
     */
    public static function getGroups(): array
    {
        return ['demo'];
    }

    /**
     * @return string[]
     */
    public function getDependencies(): array
    {
        return [TenantFixture::class];
    }
}
