<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AppUser;
use App\Entity\Tenant;
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
 */
final class AppUserFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public const REF_ADMIN = 'demo_admin';
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
        $user->setTenant($tenant);
        $user->setEmail(self::EMAIL);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setIsActive(true);
        $user->setPasswordHash(
            $this->passwordHasher->hashPassword($user, self::PASSWORD)
        );

        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::REF_ADMIN, $user);
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
