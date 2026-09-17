<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Expectation\ReturnContract;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Librarian;
use Rasuvaeff\Understudy\Tests\Fixture\Ret\PlainRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Ret\RepositoryAndLibrarian;
use Rasuvaeff\Understudy\Tests\Fixture\Ret\Stringy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The type table `returns()` consults, one rendered type at a time. The rule
 * it enforces is "no stricter than the engine": a generated method is not
 * under `strict_types`, so every scalar takes every scalar, `string` takes a
 * `Stringable`, and a type the table cannot read accepts anything.
 */
#[Test]
#[Covers(ReturnContract::class)]
final class ReturnContractTest
{
    #[DataProvider('verdictProvider')]
    public function judgesAValueAgainstTheRenderedType(string $type, mixed $value, bool $accepted): void
    {
        Assert::same($this->contract($type)->accepts($value), $accepted);
    }

    public static function verdictProvider(): iterable
    {
        $book = new Book('x');
        $stringable = new Stringy();
        $repository = new PlainRepository();

        yield 'mixed takes anything' => ['mixed', $book, true];
        yield 'mixed takes null' => ['mixed', null, true];
        yield 'nullable takes null' => ['?int', null, true];
        yield 'nullable takes the type' => ['?int', 5, true];
        yield 'nullable refuses the wrong type' => ['?int', [], false];
        yield 'int takes int' => ['int', 5, true];
        yield 'int takes a numeric string (engine coerces)' => ['int', '5', true];
        yield 'int takes bool (engine coerces)' => ['int', true, true];
        yield 'int refuses null' => ['int', null, false];
        yield 'int refuses array' => ['int', [], false];
        yield 'int refuses object' => ['int', $book, false];
        yield 'float takes int' => ['float', 1, true];
        yield 'float refuses array' => ['float', [1], false];
        yield 'bool takes string' => ['bool', 'x', true];
        yield 'bool refuses object' => ['bool', $book, false];
        yield 'true takes scalar' => ['true', 1, true];
        yield 'true refuses null' => ['true', null, false];
        yield 'false takes scalar' => ['false', 0, true];
        yield 'false refuses array' => ['false', [], false];
        yield 'string takes string' => ['string', 'x', true];
        yield 'string takes int' => ['string', 1, true];
        yield 'string takes Stringable' => ['string', $stringable, true];
        yield 'string refuses a plain object' => ['string', $book, false];
        yield 'string refuses null' => ['string', null, false];
        yield 'array takes array' => ['array', [], true];
        yield 'array refuses object' => ['array', $book, false];
        yield 'array refuses string' => ['array', 'x', false];
        yield 'iterable takes array' => ['iterable', [], true];
        yield 'iterable takes Traversable' => ['iterable', new \ArrayIterator([]), true];
        yield 'iterable refuses string' => ['iterable', 'x', false];
        yield 'callable takes closure' => ['callable', static fn(): int => 1, true];
        yield 'callable refuses int' => ['callable', 1, false];
        yield 'object takes object' => ['object', $book, true];
        yield 'object refuses array' => ['object', [], false];
        yield 'null takes null' => ['null', null, true];
        yield 'null refuses int' => ['null', 0, false];
        yield 'void takes null' => ['void', null, true];
        yield 'void refuses a value' => ['void', 1, false];
        yield 'never refuses everything' => ['never', null, false];
        yield 'class takes instance' => ['\\' . Book::class, $book, true];
        yield 'class refuses another class' => ['\\' . Book::class, $stringable, false];
        yield 'class refuses scalar' => ['\\' . Book::class, 'x', false];
        yield 'interface takes implementation' => ['\\' . BookRepository::class, $repository, true];
        yield 'self takes the contract' => ['self', $repository, true];
        yield 'self refuses another object' => ['self', $book, false];
        yield 'static takes the contract' => ['static', $repository, true];
        yield 'static refuses another object' => ['static', $book, false];
        yield 'union takes either branch' => ['int|string', 'x', true];
        yield 'union takes null branch' => ['int|null', null, true];
        yield 'union refuses what no branch takes' => ['int|string', [], false];
        yield 'union with class branch' => ['\\' . Book::class . '|null', $book, true];
        yield 'intersection takes both' => ['\\' . BookRepository::class . '&\\' . Librarian::class, new RepositoryAndLibrarian(), true];
        yield 'intersection refuses one of two' => ['\\' . BookRepository::class . '&\\' . Librarian::class, $repository, false];
        yield 'dnf: parenthesised intersection branch' => ['(\\' . BookRepository::class . '&\\' . Librarian::class . ')|null', null, true];
        yield 'dnf: refuses one half of the intersection' => ['(\\' . BookRepository::class . '&\\' . Librarian::class . ')|null', $repository, false];
        yield 'unknown type accepts anything' => ['\\Nope\\Missing', 'x', true];
        yield 'unknown type accepts null' => ['\\Nope\\Missing', null, true];
    }

    private function contract(string $type): ReturnContract
    {
        return new ReturnContract(
            'BookRepository',
            'method',
            new MethodSignature(
                name: 'method',
                parameters: '',
                arguments: '[]',
                returnType: $type,
                returnsNever: $type === 'never',
                returnsVoid: $type === 'void',
                returnsReference: false,
            ),
            BookRepository::class,
        );
    }
}
