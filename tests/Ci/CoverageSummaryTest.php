<?php

declare(strict_types=1);

namespace App\Tests\Ci;

use PHPUnit\Framework\TestCase;
use Znamky\Ci\CoverageSummary;

require_once dirname(__DIR__, 2).'/.github/scripts/coverage-summary.php';

final class CoverageSummaryTest extends TestCase
{
    public function testFromCloverBuildsSummaryAndSortsFilesByLineCoverage(): void
    {
        $markdown = (new CoverageSummary())->fromClover(self::fixture(), '/project');

        self::assertStringContainsString("| Lines | 6 | 8 | 75.00% |", $markdown);
        self::assertStringContainsString("| Methods | 2 | 4 | 50.00% |", $markdown);
        self::assertStringContainsString("| Classes | 1 | 3 | 33.33% |", $markdown);
        self::assertStringContainsString("| Branches | 1 | 4 | 25.00% |", $markdown);

        $low = strpos($markdown, '`src/Low.php`');
        $covered = strpos($markdown, '`src/Covered.php`');
        $partial = strpos($markdown, '`src/Partial.php`');
        self::assertNotFalse($low);
        self::assertNotFalse($covered);
        self::assertNotFalse($partial);
        self::assertLessThan($covered, $low);
        self::assertLessThan($partial, $covered);
        self::assertStringContainsString('| `src/Low.php` | 0/2 | 0/1 | 0.00% |', $markdown);
        self::assertStringContainsString('| `src/Covered.php` | 2/2 | 1/1 | 100.00% |', $markdown);
        self::assertStringContainsString('| `src/Partial.php` | 4/4 | 1/2 | 100.00% |', $markdown);
        self::assertStringNotContainsString('Empty.php', $markdown);
    }

    public function testFromCloverRejectsReportWithoutProjectMetrics(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CoverageSummary())->fromClover('<coverage></coverage>', '/project');
    }

    public function testCliWritesStdoutAndGitHubStepSummary(): void
    {
        $directory = sys_get_temp_dir().'/coverage-summary-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $clover = $directory.'/clover.xml';
        $summary = $directory.'/summary.md';
        file_put_contents($clover, self::fixture());

        $script = dirname(__DIR__, 2).'/.github/scripts/coverage-summary.php';
        $ok = self::runScript($script, [$clover], ['GITHUB_STEP_SUMMARY' => $summary]);
        self::assertSame(0, $ok['exit']);
        self::assertStringContainsString('| Classes | 1 | 3 | 33.33% |', $ok['stdout']);
        self::assertStringContainsString('| Classes | 1 | 3 | 33.33% |', (string) file_get_contents($summary));

        $missing = self::runScript($script, [$directory.'/missing.xml']);
        self::assertSame(1, $missing['exit']);
        self::assertStringContainsString('Coverage file not found', $missing['stderr']);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private static function runScript(string $script, array $arguments, array $environment = []): array
    {
        $command = array_merge([\PHP_BINARY, $script], $arguments);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $spec, $pipes, dirname(__DIR__, 2), $environment + getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => false === $stdout ? '' : $stdout,
            'stderr' => false === $stderr ? '' : $stderr,
        ];
    }

    private static function fixture(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage generated="1">
              <project timestamp="1" name="Clover Coverage">
                <file name="/project/src/Low.php">
                  <class name="App\Low" namespace="App">
                    <metrics complexity="1" methods="1" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="0" elements="3" coveredelements="0"/>
                  </class>
                  <metrics loc="10" ncloc="8" classes="1" methods="1" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="0" elements="3" coveredelements="0"/>
                </file>
                <package name="App">
                  <file name="/project/src/Covered.php">
                    <class name="App\Covered" namespace="App">
                      <metrics complexity="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="2" elements="3" coveredelements="3"/>
                    </class>
                    <class name="App\Empty" namespace="App">
                      <metrics complexity="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="0" coveredstatements="0" elements="0" coveredelements="0"/>
                    </class>
                    <metrics loc="10" ncloc="8" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="2" elements="3" coveredelements="3"/>
                  </file>
                  <file name="/project/src/Partial.php">
                    <class name="App\Partial" namespace="App">
                      <metrics complexity="2" methods="2" coveredmethods="1" conditionals="4" coveredconditionals="1" statements="4" coveredstatements="4" elements="10" coveredelements="6"/>
                    </class>
                    <metrics loc="20" ncloc="16" classes="1" methods="2" coveredmethods="1" conditionals="4" coveredconditionals="1" statements="4" coveredstatements="4" elements="10" coveredelements="6"/>
                  </file>
                </package>
                <metrics files="3" loc="40" ncloc="32" classes="2" methods="4" coveredmethods="2" conditionals="4" coveredconditionals="1" statements="8" coveredstatements="6" elements="16" coveredelements="9"/>
              </project>
            </coverage>
            XML;
    }
}
