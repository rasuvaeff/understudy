<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every claim about lifting `final` is made in a process of its own.
 *
 * A class is read from disk once per process and the transform happens as it is
 * read, so two scenarios in one process would prove only whichever ran first —
 * and coverage cannot see into a subprocess either, which is why
 * {@see Bypass\FinalStripperTest} exists beside this: the decisions are unit
 * tested, the end-to-end claims are made here.
 */
#[Test]
#[CoversNothing]
final class BypassFinalsTest
{
    #[DataProvider('scenarioProvider')]
    public function aScenarioAnswersAsExpected(string $scenario, string $expected): void
    {
        Assert::same($this->run($scenario), $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scenarioProvider(): iterable
    {
        yield 'a bypassed final class can be doubled' => ['targeted', 'doubled'];
        yield 'without bypass the refusal names the recipe' => ['without-bypass', 'refused with the recipe'];
        yield 'only the named class in a file is opened' => ['sibling-untouched', 'opened/still final'];
        yield 'global mode opens every class in a file' => ['global', 'opened/opened'];
        yield 'a final method survives and still refuses the target' => [
            'final-method-survives',
            'class opened, method sealed, target refused',
        ];
        yield 'final readonly stays readonly' => ['final-readonly', 'readonly double'];
        yield '__FILE__, __DIR__ and a relative include are unchanged' => ['paths-preserved', 'paths intact'];
        yield 'a file that is not PHP passes through untouched' => ['non-php-untouched', 'json intact'];
        yield 'a class already loaded is refused' => ['already-loaded', 'refused'];
        yield 'an enum is refused' => ['enum', 'refused'];
        yield 'an interface is refused' => ['interface', 'refused'];
    }

    private function run(string $scenario): string
    {
        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/Fixture/Bypass/scenario.php'),
            escapeshellarg($scenario),
        );

        $output = [];
        exec($command, $output, $status);

        Assert::same($status, 0, 'the scenario process exited with ' . $status . ': ' . implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
