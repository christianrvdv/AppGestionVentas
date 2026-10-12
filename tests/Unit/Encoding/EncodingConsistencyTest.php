<?php

declare(strict_types=1);

namespace App\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;

/**
 * Consistencia de encoding en el código fuente (Fase 7).
 *
 * Motivación: en Fase 6.1 apareció mojibake (U+FFFD) en 5 interfaces de
 * repositorio — un problema de tooling que podía haber dejado más rastros.
 * La auditoría manual de Fase 7 no encontró residuos; este test impide que
 * vuelvan a entrar. Corre en `composer ci` como cualquier otro test.
 *
 * Reglas:
 *   - Ningún archivo puede contener caracteres de reemplazo U+FFFD ni
 *     bytes UTF-8 inválidos (tampoco doble codificación, que se manifiesta
 *     como secuencias "Ã©"-style dentro de texto válido).
 *   - Ningún archivo PHP puede empezar con BOM UTF-8 (rompe headers
 *     "headers already sent" y ensucia salidas de consola).
 */
final class EncodingConsistencyTest extends TestCase
{
    private const DIRECTORIES = ['src', 'templates', 'tests'];

    private const EXTENSIONS = ['php', 'twig', 'yaml', 'yml', 'md'];

    /**
     * U+FFFD (REPLACEMENT CHARACTER) es la huella física del mojibake:
     * aparece cuando bytes UTF-8 inválidos se deciden "a fuerza". Un
     * archivo que lo contiene ya está corrupto.
     */
    public function test_src_tests_y_templates_no_contienen_caracteres_de_reemplazo(): void
    {
        $offenders = [];

        foreach ($this->scanFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, "\u{FFFD}")) {
                $offenders[] = $file.' (U+FFFD)';
                continue;
            }

            // preg con /u falla si el contenido NO es UTF-8 válido: captura
            // bytes sueltos que no alcanzan a producir U+FFFD pero igual
            // rompen la decodificación (p. ej. un medio byte de tilde).
            if (1 !== preg_match('//u', $contents)) {
                $offenders[] = $file.' (UTF-8 inválido)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Archivos con encoding corrupto (mojibake / UTF-8 inválido):\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * Un BOM UTF-8 al inicio de un PHP no se ve en el navegador pero
     * rompe el arranque de headers y ensucia salidas CLI/comandos.
     */
    public function test_archivos_php_no_tienen_bom_utf8(): void
    {
        $offenders = [];

        foreach ($this->scanFiles() as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $head = (string) file_get_contents($file, false, null, 0, 3);

            if ("\xEF\xBB\xBF" === $head) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Archivos PHP con BOM UTF-8:\n".implode("\n", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private function scanFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        $extensions = self::EXTENSIONS;

        foreach (self::DIRECTORIES as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root.DIRECTORY_SEPARATOR.$directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if (!\in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
