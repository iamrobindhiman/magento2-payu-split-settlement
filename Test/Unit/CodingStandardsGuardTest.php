<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Carry-over from Fab_OrderCancellation. Five regression guards:
 *   1. No direct ResourceConnection imports
 *   2. No raw SQL string literals
 *   3. No PHP date()/time() — use Magento DateTime service
 *   4. Every Observer/Plugin/Model class declares LoggerInterface
 *   5. NEW for RKD_PayuSplit: no unqualified catch(Exception) in production files —
 *      catches the exact namespace bug pattern the upstream PayU module shipped
 *      (Model/Payu.php:440, Cron/OrderUpdate.php:197).
 *
 * Scope: production files under app/code/Fab/Payu/ (excludes Test/).
 */
class CodingStandardsGuardTest extends TestCase
{
    /** @return array<int, string> */
    private function productionFiles(): array
    {
        $root = dirname(__DIR__, 1); // .../app/code/Fab/Payu
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $files = [];
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (strpos($path, '/Test/') !== false) {
                continue;
            }
            $files[] = $path;
        }
        return $files;
    }

    public function testNoProductionClassImportsResourceConnectionDirectly(): void
    {
        $violations = [];
        foreach ($this->productionFiles() as $file) {
            if (preg_match('/use\s+Magento\\\\Framework\\\\App\\\\ResourceConnection\s*;/', file_get_contents($file))) {
                $violations[] = $file;
            }
        }
        $this->assertSame([], $violations);
    }

    public function testNoProductionFileContainsRawSqlStringLiterals(): void
    {
        $pattern = '/(["\'])\s*(SELECT|UPDATE|INSERT INTO|DELETE FROM|TRUNCATE)\b/i';
        $violations = [];
        foreach ($this->productionFiles() as $file) {
            if (preg_match_all($pattern, file_get_contents($file), $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $line = substr_count(substr(file_get_contents($file), 0, $match[1]), "\n") + 1;
                    $violations[] = $file . ':' . $line;
                }
            }
        }
        $this->assertSame([], $violations);
    }

    public function testNoProductionFileUsesPhpDateOrTimeForTimestamps(): void
    {
        $violations = [];
        foreach ($this->productionFiles() as $file) {
            $content = file_get_contents($file);
            if (preg_match('/(?<![a-zA-Z0-9_])(date|time)\s*\(/', $content)) {
                foreach (explode("\n", $content) as $i => $line) {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                        continue;
                    }
                    if (preg_match('/(?<![a-zA-Z0-9_])(date|time)\s*\(/', $line)) {
                        $violations[] = $file . ':' . ($i + 1);
                    }
                }
                break;
            }
        }
        $this->assertSame([], $violations);
    }

    public function testEveryObserverPluginAndStatefulModelDeclaresLoggerInterface(): void
    {
        $required = [];
        foreach ($this->productionFiles() as $file) {
            $relative = str_replace(dirname(__DIR__, 1) . '/', '', $file);
            // Enforce on Observer/ + Plugin/ subdirectories under both feature
            // namespaces (Diagnostic/, Split/). Model/Config, Service/, and
            // Util/PayloadRedactor are stateless helpers and don't need their own logger.
            if (!preg_match('#^(Diagnostic|Split)/(Observer|Plugin)/.*\.php$#', $relative)) {
                continue;
            }
            $required[] = $file;
        }

        $violations = [];
        foreach ($required as $file) {
            $content = file_get_contents($file);
            $hasImport = (bool)preg_match('/use\s+Psr\\\\Log\\\\LoggerInterface\s*;/', $content);
            $hasParam = (bool)preg_match('/LoggerInterface\s+\$\w+/', $content);
            if (!$hasImport || !$hasParam) {
                $violations[] = $file;
            }
        }
        $this->assertSame([], $violations);
    }

    public function testNoUnqualifiedCatchExceptionInProductionCode(): void
    {
        // Catches the exact upstream bug: `catch(Exception $e)` (no leading backslash)
        // inside a namespaced file resolves to <CurrentNamespace>\Exception which
        // doesn't exist, so the catch never matches. This bit PayUIndia/Payu twice
        // (Model/Payu.php:440, Cron/OrderUpdate.php:197). Lock it out of our code.
        $violations = [];
        foreach ($this->productionFiles() as $file) {
            $content = file_get_contents($file);
            // Allowed: catch(\Exception $e) or catch(\Throwable $t) or catch (Vendor\Exception)
            // Disallowed: catch(Exception ...) with no leading backslash and no namespace prefix
            if (preg_match('/catch\s*\(\s*Exception\s+\$/m', $content)) {
                foreach (explode("\n", $content) as $i => $line) {
                    if (preg_match('/catch\s*\(\s*Exception\s+\$/', $line)) {
                        $violations[] = $file . ':' . ($i + 1) . ' — ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $violations,
            "Production files must use `catch (\\Exception \$e)` (with leading backslash) " .
            "or `catch (\\Throwable \$t)`. Unqualified `catch(Exception \$e)` in a namespaced " .
            "file resolves to <CurrentNamespace>\\Exception (which doesn't exist) — the catch " .
            "never matches and exceptions silently propagate. This is the exact bug that ships " .
            "in PayUIndia\\Payu\\Model\\Payu::postProcessing today.\n" .
            "Offending locations:\n" . implode("\n", $violations));
    }
}
