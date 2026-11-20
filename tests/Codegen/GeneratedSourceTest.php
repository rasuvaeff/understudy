<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\ReadonlyLedger;
use Rasuvaeff\Understudy\Tests\Fixture\Dnf\Shapes;
use Rasuvaeff\Understudy\Tests\Fixture\Ref\Registry;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\AbstractOwnStatic;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterString;
use Rasuvaeff\Understudy\Tests\Fixture\VariadicSink;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Golden files of the class `eval()` receives, one per signature shape.
 *
 * Codegen defects reached users as fatals out of `eval()` — uncatchable, and
 * reported against a line of a class nobody can see. A golden file makes the
 * generated source something a diff can review. There is no update flag: a
 * deliberate change edits `tests/fixtures/codegen/<name>.php.txt` by hand, and
 * that diff is the review. The class name is a hash of the contract names and
 * is replaced by a placeholder, so renaming a fixture does not rewrite a file.
 */
#[Test]
#[Covers(DoubleFactory::class)]
final class GeneratedSourceTest
{
    /**
     * @param non-empty-list<class-string> $contracts
     */
    #[DataProvider('shapesProvider')]
    public function generatedSourceMatchesItsGoldenFile(string $name, array $contracts): void
    {
        Assert::same(self::normalize(DoubleFactory::sourceFor($contracts)), self::golden($name));
    }

    /**
     * @param non-empty-list<class-string> $contracts
     */
    #[DataProvider('shapesProvider')]
    public function theSourceIsWhatTheFactoryEvaluates(string $name, array $contracts): void
    {
        $blueprint = DoubleFactory::blueprintFor($contracts);
        $short = substr($blueprint->generatedClass, strlen(DoubleFactory::GENERATED_NAMESPACE));

        Assert::string(DoubleFactory::sourceFor($contracts))->contains('final class ' . $short . ' ');
    }

    public function aRepeatedContractIsRenderedOnce(): void
    {
        Assert::same(
            DoubleFactory::sourceFor([BookRepository::class, BookRepository::class]),
            DoubleFactory::sourceFor([BookRepository::class]),
        );
    }

    public static function shapesProvider(): iterable
    {
        yield 'interface' => ['interface', [BookRepository::class]];
        yield 'variadic' => ['variadic', [VariadicSink::class]];
        yield 'by reference' => ['by-reference', [Registry::class]];
        yield 'readonly class' => ['readonly-class', [ReadonlyLedger::class]];
        yield 'two targets unified' => ['unified', [WriterInt::class, WriterString::class]];
        yield 'abstract static' => ['abstract-static', [AbstractOwnStatic::class]];
        yield 'dnf returns' => ['dnf', [Shapes::class]];
    }

    private static function normalize(string $source): string
    {
        return (string) preg_replace('/\bUnderstudy_[0-9a-f]{16}\b/', 'Understudy_GENERATED', $source);
    }

    private static function golden(string $name): string
    {
        $path = __DIR__ . '/../fixtures/codegen/' . $name . '.php.txt';

        if (!is_file($path)) {
            throw new \RuntimeException('Missing golden source file: ' . $path);
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }
}
